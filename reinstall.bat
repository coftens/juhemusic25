@echo off
echo Reinstalling optimized APK (faster startup)...
echo.

set ADB=C:\leidian\LDPlayer9\adb.exe
set APK=music-debug-v1.0.7-fast.apk

echo [1] Uninstall old version...
%ADB% uninstall com.example.flutter_app >nul 2>&1

echo [2] Installing new APK v1.0.7...
%ADB% install "%APK%"

echo [3] Launching app...
timeout /t 2 /nobreak >nul
%ADB% shell am start -n com.example.flutter_app/.MainActivity

echo.
echo ========================================
echo Installation Complete!
echo ========================================
echo.
echo OPTIMIZATIONS:
echo   - Prioritizes standard/320kbps quality
echo   - Avoids failed FLAC attempts
echo   - Faster music startup
echo.
echo Now run: logs.bat to see debug output
echo.

pause
