@echo off
echo ============================================
echo Setting up Guzzle HTTP Client with Composer
echo ============================================
echo.

REM Check if composer.phar exists, if not download it
if not exist "composer.phar" (
    echo composer.phar not found. Downloading...
    echo.
    
    REM Download composer.phar using PowerShell
    powershell -Command "& {[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri 'https://getcomposer.org/composer.phar' -OutFile 'composer.phar'}"
    
    if %ERRORLEVEL% NEQ 0 (
        echo ERROR: Failed to download composer.phar
        echo Please check your internet connection and try again.
        pause
        exit /b 1
    )
    
    echo ✓ composer.phar downloaded successfully!
    echo.
) else (
    echo ✓ Found existing composer.phar
    echo.
)

REM Create composer.json file
echo Creating composer.json file...
(
echo {
echo   "name": "gentool-replay-downloader/http-client",
echo   "description": "Replay Download Tool with HTTP Client",
echo   "require": {
echo     "guzzlehttp/guzzle": "^7.0"
echo   }
echo }
) > composer.json

echo composer.json created successfully!
echo.

REM Install dependencies
echo Installing Guzzle and dependencies...
echo This may take a moment...
echo.
php composer.phar install

REM Check if installation was successful
if %ERRORLEVEL% NEQ 0 (
    echo.
    echo ERROR: Composer install failed!
    echo Please check the error messages above.
    pause
    exit /b 1
)

echo.
echo ============================================
echo Installation completed successfully!
echo ============================================
echo.

REM Check if vendor directory was created
if exist "vendor\autoload.php" (
    echo ✓ vendor/autoload.php created
) else (
    echo ✗ vendor/autoload.php not found
)

if exist "vendor\guzzlehttp" (
    echo ✓ Guzzle installed successfully
) else (
    echo ✗ Guzzle directory not found
)

echo.
echo Your project structure now looks like:
echo   %CD%
echo   ├── composer.phar
echo   ├── composer.json
echo   ├── composer.lock
echo   └── vendor/
echo       ├── autoload.php
echo       ├── guzzlehttp/
echo       └── ... (other dependencies)
echo.


echo ============================================
echo Setup Complete!
echo ============================================
