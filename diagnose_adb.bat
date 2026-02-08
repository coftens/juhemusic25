@echo off
echo ========================================
echo 雷电模拟器 ADB 诊断工具
echo ========================================
echo.

set ADB=C:\leidian\LDPlayer9\adb.exe

echo [1] 检查ADB版本...
%ADB% version
echo.

echo [2] 关闭所有ADB进程...
taskkill /F /IM adb.exe >nul 2>&1
timeout /t 1 /nobreak >nul
echo.

echo [3] 启动ADB服务器...
%ADB% start-server
timeout /t 2 /nobreak >nul
echo.

echo [4] 检查端口5555状态...
netstat -ano | findstr :5555 | findstr LISTENING
echo.

echo [5] 尝试连接模拟器...
echo 正在连接 127.0.0.1:5555...
timeout /t 2 /nobreak >nul
%ADB% connect 127.0.0.1:5555
echo.

echo [6] 检查已连接设备...
timeout /t 1 /nobreak >nul
%ADB% devices
echo.

echo [7] 如果flutter_app已安装，尝试启动...
%ADB% shell am start -n com.example.flutter_app/.MainActivity >nul 2>&1
echo.

echo ========================================
echo 诊断完成
echo ========================================
echo.
echo 如果上面显示设备已连接，可以查看日志了
echo.

pause
