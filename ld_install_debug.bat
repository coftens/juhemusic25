@echo off

echo ==========================================
echo      Music App - LDPlayer Installer
echo ==========================================

echo [1/4] Connecting to LDPlayer...
"C:\leidian\LDPlayer9\adb.exe" connect 127.0.0.1:5555

echo [2/4] Installing APK to 127.0.0.1:5555...
"C:\leidian\LDPlayer9\adb.exe" -s 127.0.0.1:5555 install -r "flutter_app\build\app\outputs\flutter-apk\app-debug.apk"

if %errorlevel% neq 0 (
    echo.
    echo [ERROR] Installation failed!
    echo Check if LDPlayer is running.
    echo.
    pause
    exit /b
)

echo.
echo [3/4] SUCCESS!
echo.
echo To view logs, run:
echo cd flutter_app
echo flutter logs
echo.
pause