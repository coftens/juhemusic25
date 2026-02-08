@echo off
echo =============================================
echo   Music Backend Services Launcher
echo =============================================
echo.

echo [1/2] Starting Python Server (Port 8002)...
start "Python Music Server" cmd /k "cd /d %~dp0 && python server.py"
timeout /t 2 /nobreak >nul

echo [2/2] Starting QQ Bridge Server (Port 8003)...
start "QQ Bridge Server" cmd /k "cd /d %~dp0 && node qq_bridge_server.js"
timeout /t 2 /nobreak >nul

echo.
echo =============================================
echo   All services started!
echo =============================================
echo   - Python Server: http://localhost:8002
echo   - QQ Bridge: http://localhost:8003
echo.
echo Press any key to exit (services will keep running)
pause >nul
