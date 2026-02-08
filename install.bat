@echo off
set ADB=C:\leidian\LDPlayer9\adb.exe

echo Installing APK...
echo.

if exist "%~1" (
    %ADB% install "%~1"
) else (
    echo Error: APK file not found
    echo Usage: install.bat [apk-file]
    echo.
    echo Example: install.bat music-debug-v1.0.1.apk
)

echo.
pause
