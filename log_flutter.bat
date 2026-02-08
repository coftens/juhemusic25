@echo off
set ADB=C:\leidian\LDPlayer9\adb.exe

echo 正在查看 flutter_app 实时日志...
echo 按 Ctrl+C 停止
echo.
echo 正在启动日志查看器...
%ADB% logcat -s flutter_app
