@echo off
set ADB=C:\leidian\LDPlayer9\adb.exe

echo Select device:
echo.
%ADB% devices
echo.

echo Device options:
echo   1 - 127.0.0.1:5555
echo   2 - emulator-5554
echo   3 - Use first device automatically
echo.

set /p choice="Select device (1/2/3): "

if "%choice%"=="1" (
    set DEVICE_ID=127.0.0.1:5555
) else if "%choice%"=="2" (
    set DEVICE_ID=emulator-5554
) else (
    set DEVICE_ID=
)

if "%DEVICE_ID%"=="" (
    echo Using first available device...
    set DEVICE_ID=-d
) else (
    echo Selected device: %DEVICE_ID%
)

echo.
echo Viewing flutter_app logs from device: %DEVICE_ID%
echo Press Ctrl+C to stop
echo.

%ADB% -s %DEVICE_ID% logcat -s flutter_app
