@echo off
set ADB_PATH=C:\leidian\LDPlayer9\adb.exe

if "%1"=="" (
    echo 雷电模拟器 ADB 工具
    echo.
    echo 用法:
    echo   ld_adb connect    - 连接模拟器
    echo   ld_adb devices    - 查看设备
    echo   ld_adb log        - 查看flutter日志
    echo   ld_adb install    - 安装APK
    echo   ld_adb help       - 显示帮助
    echo.
    goto :end
)

if "%1"=="connect" (
    %ADB_PATH% connect 127.0.0.1:5555
    %ADB_PATH% devices
    goto :end
)

if "%1"=="devices" (
    %ADB_PATH% devices
    goto :end
)

if "%1"=="log" (
    %ADB_PATH% logcat | findstr "flutter_app"
    goto :end
)

if "%1"=="install" (
    if "%2"=="" (
        echo 请指定APK文件路径
        echo 例如: ld_adb install music-debug-v1.0.1.apk
        goto :end
    )
    %ADB_PATH% install %2
    goto :end
)

if "%1"=="help" (
    echo 雷电模拟器 ADB 工具
    echo.
    echo 用法:
    echo   ld_adb connect           - 连接模拟器 (127.0.0.1:5555)
    echo   ld_adb devices           - 查看已连接设备
    echo   ld_adb log               - 查看flutter_app日志
    echo   ld_adb install <apk>     - 安装APK
    echo   ld_adb start             - 启动应用
    echo   ld_adb clear             - 清空日志
    echo   ld_adb help              - 显示此帮助
    echo.
    echo 快捷命令:
    echo   cd C:\Users\Coftens\Desktop\xiangmu\music
    echo   ld_adb connect
    echo   ld_adb log
    goto :end
)

if "%1"=="start" (
    %ADB_PATH% shell am start -n com.example.flutter_app/.MainActivity
    goto :end
)

if "%1"=="clear" (
    %ADB_PATH% logcat -c
    echo 日志已清空
    goto :end
)

echo 未知命令: %1
echo 使用 'ld_adb help' 查看帮助

:end
