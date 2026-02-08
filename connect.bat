@echo off
echo ========================================
echo LD Player ADB Diagnostic Tool
echo ========================================
echo.

set ADB=C:\leidian\LDPlayer9\adb.exe

echo [1] Check ADB version...
%ADB% version
echo.

echo [2] Kill all ADB processes...
taskkill /F /IM adb.exe >nul 2>&1
timeout /t 1 /nobreak >nul
echo.

echo [3] Start ADB server...
%ADB% start-server
timeout /t 2 /nobreak >nul
echo.

echo [4] Check port 5555 status...
netstat -ano | findstr :5555 | findstr LISTENING
echo.

echo [5] Try to connect emulator...
echo Connecting to 127.0.0.1:5555...
timeout /t 2 /nobreak >nul
%ADB% connect 127.0.0.1:5555
echo.

echo [6] Check connected devices...
timeout /t 1 /nobreak >nul
%ADB% devices
echo.

echo [7] Try to start flutter_app...
%ADB% shell am start -n com.example.flutter_app/.MainActivity >nul 2>&1
echo.

echo ========================================
echo Diagnostic Complete
echo ========================================
echo.
echo If device is shown above, run:
echo    %ADB% logcat -s flutter_app
echo.
pause
