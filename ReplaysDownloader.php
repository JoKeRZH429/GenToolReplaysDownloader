<?php

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

define('REPLAY_DIR', __DIR__ . '/replays');
define('MAX_CONCURRENT_DOWNLOADS', 50);

define('GENTOOL_BASE_URL', 'https://www.gentool.net/');
define('GENTOOL_LOGS_URL', 'https://www.gentool.net/data/zh/logs/');
define('REQUIRED_GAME_VERSION', 'Game Version:     Zero Hour 1.04');
define('EXCLUDED_GAME_VERSION', 'Game Version:     Zero Hour 1.04 The First Decade');
define('REQUIRED_INSTALL_TYPE', 'Install Type:     Normal Game Install');



(function() {
    date_default_timezone_set('GMT');
    
    $options = getopt("h:", ["hours:"]);
    $hoursBack = $options['h'] ?? $options['hours'] ?? null;

    if ($hoursBack === null) {
        echo "Error: Hours parameter is required.\n";
        echo "Usage: php download_replays.php -h <hours> OR php download_replays.php --hours <hours>\n";
        echo "Example: php download_replays.php -h 48\n";
        exit(1);
    }

    if (!is_numeric($hoursBack) || $hoursBack <= 0) {
        echo "Error: Hours must be a positive number.\n";
        exit(1);
    }
    
    $client = new Client([
        'timeout' => 30,
        'headers' => ['User-Agent' => 'Replay-Downloader/2.1'],
        'allow_redirects' => ['max' => 3]
    ]);

    if (!file_exists(REPLAY_DIR)) {
        mkdir(REPLAY_DIR, 0777, true);
        echo "Created replay directory at: " . REPLAY_DIR . "\n";
    }

    echo "Starting GenTool Downloader...\n";
    
    $endTime = new DateTime('now', new DateTimeZone('GMT'));
    $startTime = (clone $endTime)->modify("-{$hoursBack} hours");
    
    echo "Time Window: " . $startTime->format('Y-m-d H:i:s') . " to " . $endTime->format('Y-m-d H:i:s') . " (GMT)\n";

    $logFiles = getLogFilesForWindow($startTime, $endTime);
    echo "Found " . count($logFiles) . " log files to process.\n";
    
    if (empty($logFiles)) {
        echo "No log files found for the specified period. Exiting.\n";
        return;
    }

    $allUploads = processLogFilesConcurrently($logFiles, $client, $startTime->getTimestamp(), $endTime->getTimestamp());
    echo "Found " . count($allUploads) . " total uploads across all log files.\n";

    if (empty($allUploads)) {
        echo "No uploads found in the logs. Exiting.\n";
        return;
    }

    echo "Downloading .txt files to check game versions...\n";
    $validRepTasks = checkTxtFilesConcurrently($allUploads, $client);
    echo "Found " . count($validRepTasks) . " replays with matching criteria (Zero Hour 1.04 & Normal Game Install).\n";

    if (empty($validRepTasks)) {
        echo "No valid replays to download. Exiting.\n";
        return;
    }

    echo "Downloading valid .rep files...\n";
    downloadRepFilesConcurrently($validRepTasks, $client);

    echo "\nDownload process complete.\n";
})();


function getLogFilesForWindow(DateTime $startTime, DateTime $endTime): array {
    $logFiles = [];
    
    $startMinute = (int)$startTime->format('i');
    $adjustedStartMinute = floor($startMinute / 10) * 10;
    $current = (clone $startTime)->setTime((int)$startTime->format('H'), $adjustedStartMinute, 0);

    if ($current > $startTime) {
        $current->modify('-10 minutes');
    }
    
    $endTimestamp = $endTime->getTimestamp();

    while ($current->getTimestamp() <= $endTimestamp) {
        $date = $current->format('Y-m-d');
        $year_month = $current->format('Y_m');
        $dayNum = $current->format('d');
        $time = $current->format('His'); 

        $filename = "uploads_" . $current->format('Ymd') . "_{$time}.yaml.txt";
        $url = GENTOOL_LOGS_URL . $year_month . '/' . $dayNum . '/' . $filename;

        $logFiles[] = [
            'filename' => $filename,
            'url' => $url,
            'date' => $date,
            'start_time' => $current->getTimestamp()
        ];

        $current->modify('+10 minutes');
    }
    
    usort($logFiles, function($a, $b) {
        return $a['start_time'] <=> $b['start_time'];
    });

    $lookBackTime = $startTime->getTimestamp();
    $filteredLogFiles = [];
    $addedFirstRelevantFile = false;

    foreach ($logFiles as $logFile) {
        if ($logFile['start_time'] <= $endTimestamp) {
            if (!$addedFirstRelevantFile) {
                if ($logFile['start_time'] <= $lookBackTime) {
                    $filteredLogFiles[] = $logFile;
                    $addedFirstRelevantFile = true;
                }
            } else {
                $filteredLogFiles[] = $logFile;
            }
        }
    }

    return $filteredLogFiles;
}

function processLogFilesConcurrently(array $logFiles, Client $client, int $lookBackTime, int $endTimeStamp): array {
    $allUploads = [];
    $logFileCount = count($logFiles);
    $processedCount = 0;

    $requests = function ($logFiles) {
        foreach ($logFiles as $logFile) {
            yield new Request('GET', $logFile['url']);
        }
    };

    $pool = new Pool($client, $requests($logFiles), [
        'concurrency' => MAX_CONCURRENT_DOWNLOADS,
        'fulfilled' => function (Response $response, $index) use (&$allUploads, $logFiles, &$processedCount, $logFileCount, $lookBackTime, $endTimeStamp) {
            $logFile = $logFiles[$index];
            $content = $response->getBody()->getContents();
            $uploads = parseYamlLogContent($content, $logFile);
            
            foreach ($uploads as $upload) {
                $uploadTimestamp = strtotime($upload['upload_time']);
                
                if ($uploadTimestamp >= $lookBackTime && $uploadTimestamp <= $endTimeStamp) {
                    $allUploads[] = $upload;
                }
            }
            
            $processedCount++;
            echo "\rProcessing log files: $processedCount / $logFileCount";
        },
        'rejected' => function ($reason, $index) use (&$processedCount, $logFileCount) {
            $processedCount++;
            echo "\rProcessing log files: $processedCount / $logFileCount (1 failed)";
        },
    ]);

    $pool->promise()->wait();
    echo "\n"; 
    return $allUploads;
}

function parseYamlLogContent(string $content, array $logFileInfo): array {
    $uploads = [];
    
    $entries = explode('---', $content);
    
    foreach ($entries as $entry) {
        $entry = trim($entry);
        if (empty($entry)) continue;
        
        try {
            $upload = parseYamlEntry($entry);
            if ($upload) {
                $upload['log_file'] = $logFileInfo['filename'];
                $upload['log_date'] = $logFileInfo['date'];
                $uploads[] = $upload;
            }
        } catch (Exception $e) {
        }
    }
    
    return $uploads;
}

function parseYamlEntry(string $entry): ?array {
    $lines = explode("\n", $entry);
    $upload = [
        'files' => []
    ];
    
    $inFilesSection = false;
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        if (strpos($line, 'uploadtime:') === 0) {
            $upload['upload_time'] = trim(substr($line, 11));
        } elseif (strpos($line, 'username:') === 0) {
            $upload['username'] = trim(substr($line, 9));
        } elseif (strpos($line, 'userid:') === 0) {
            $upload['user_id'] = trim(substr($line, 7));
        } elseif (strpos($line, 'version:') === 0) {
            $upload['version'] = trim(substr($line, 8));
        } elseif (strpos($line, 'files:') === 0) {
            $inFilesSection = true;
        } elseif ($inFilesSection && strpos($line, '- ') === 0) {
            $filePath = trim(substr($line, 2));
            $upload['files'][] = $filePath;
        }
    }
    
    if (empty($upload['upload_time']) || empty($upload['username']) || 
        empty($upload['user_id']) || empty($upload['files'])) {
        return null;
    }
    
    $upload['replay_files'] = processReplayFiles($upload['files']);
    
    return $upload;
}

function processReplayFiles(array $files): array {
    $replayFiles = [];
    $repFiles = [];
    $txtFiles = [];

    foreach ($files as $file) {
        if (substr($file, -4) === '.rep') {
            $repFiles[] = $file;
        } elseif (substr($file, -4) === '.txt') {
            $txtFiles[] = $file;
        }
    }

    foreach ($repFiles as $repFile) {
        $baseName = substr(basename($repFile), 0, -4);
        
        $txtFile = null;
        foreach ($txtFiles as $txt) {
            if (strpos(basename($txt), $baseName) === 0) {
                $txtFile = $txt;
                break;
            }
        }
        
        if ($txtFile) {
            $replayFiles[] = [
                'rep_file' => basename($repFile),
                'txt_file' => basename($txtFile),
                'rep_path' => $repFile,
                'txt_path' => $txtFile,
                'rep_url' => GENTOOL_BASE_URL . $repFile,
                'txt_url' => GENTOOL_BASE_URL . $txtFile
            ];
        }
    }
    
    return $replayFiles;
}

function checkTxtFilesConcurrently(array $allUploads, Client $client): array {
    $validRepTasks = [];
    $txtDownloadTasks = [];
    
    foreach($allUploads as $upload){
        foreach($upload['replay_files'] as $filePair){
            $txtDownloadTasks[] = $filePair;
        }
    }

    $totalTasks = count($txtDownloadTasks);
    $processedCount = 0;

    $requests = function ($tasks) {
        foreach ($tasks as $task) {
            yield new Request('GET', $task['txt_url']);
        }
    };

    $pool = new Pool($client, $requests($txtDownloadTasks), [
        'concurrency' => MAX_CONCURRENT_DOWNLOADS,
        'fulfilled' => function (Response $response, $index) use (&$validRepTasks, $txtDownloadTasks, &$processedCount, $totalTasks) {
            $content = $response->getBody()->getContents();
            if (strpos($content, REQUIRED_GAME_VERSION) !== false && 
                strpos($content, EXCLUDED_GAME_VERSION) === false &&
                strpos($content, REQUIRED_INSTALL_TYPE) !== false) {
                $validRepTasks[] = $txtDownloadTasks[$index];
            }
            $processedCount++;
            echo "\rChecking replays: $processedCount / $totalTasks";
        },
        'rejected' => function ($reason, $index) use (&$processedCount, $totalTasks) {
            $processedCount++;
            echo "\rChecking replays: $processedCount / $totalTasks (1 failed)";
        },
    ]);

    $pool->promise()->wait();
    echo "\n";
    return $validRepTasks;
}

/**
 * Generate a unique filename by appending (1), (2), etc. if file already exists
 */
function getUniqueFileName(string $filePath): string {
    if (!file_exists($filePath)) {
        return $filePath;
    }
    
    $pathInfo = pathinfo($filePath);
    $directory = $pathInfo['dirname'];
    $filename = $pathInfo['filename'];
    $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
    
    $counter = 1;
    do {
        $newFilePath = $directory . DIRECTORY_SEPARATOR . $filename . " ($counter)" . $extension;
        $counter++;
    } while (file_exists($newFilePath));
    
    return $newFilePath;
}

function downloadRepFilesConcurrently(array $repTasks, Client $client): void {
    $totalTasks = count($repTasks);
    $downloadedCount = 0;
    $failedCount = 0;
    $duplicateCount = 0;

    // Check for duplicate filenames before downloading
    $filenames = array_column($repTasks, 'rep_file');
    $uniqueFilenames = array_unique($filenames);
    $duplicateCount = count($filenames) - count($uniqueFilenames);
    
    if ($duplicateCount > 0) {
        echo "Found $duplicateCount duplicate filenames that will be renamed.\n";
    }

    $requests = function ($tasks) {
        foreach ($tasks as $task) {
            yield new Request('GET', $task['rep_url']);
        }
    };

    $pool = new Pool($client, $requests($repTasks), [
        'concurrency' => MAX_CONCURRENT_DOWNLOADS,
        'fulfilled' => function (Response $response, $index) use ($repTasks, &$downloadedCount, $totalTasks) {
            $task = $repTasks[$index];
            $originalFilePath = REPLAY_DIR . '/' . $task['rep_file'];
            $uniqueFilePath = getUniqueFileName($originalFilePath);
            
            file_put_contents($uniqueFilePath, $response->getBody()->getContents());
            $downloadedCount++;
            echo "\rDownloading replays: $downloadedCount / $totalTasks";
        },
        'rejected' => function ($reason, $index) use (&$downloadedCount, &$failedCount, $totalTasks) {
            $failedCount++;
            $downloadedCount++;
            echo "\rDownloading replays: $downloadedCount / $totalTasks ($failedCount failed)";
        },
    ]);
    
    $pool->promise()->wait();
    echo "\n";
    
    if ($failedCount > 0) {
        echo "Failed to download $failedCount replays.\n";
    }
}