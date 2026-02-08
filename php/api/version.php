<?php
header('Content-Type: application/json; charset=utf-8');

// ==================== 配置区 ====================
// 最新版本号（App 会与此比较，若小于则提示更新）
$latestVersion = "7.06";

// APK 下载链接
$downloadUrl = "https://your-server.com/download/app-v7.06.apk";

// 是否强制更新（true = 用户无法跳过更新弹窗）
$forceUpdate = false;

// 更新日志（支持换行符 \n）
$changelog = "- 新增版本检查功能\n- 修复收藏按钮问题\n- 优化性能体验";
// ================================================

// 返回 JSON
echo json_encode([
    'code' => 200,
    'data' => [
        'version' => $latestVersion,
        'download_url' => $downloadUrl,
        'force' => $forceUpdate,
        'changelog' => $changelog,
    ],
], JSON_UNESCAPED_UNICODE);
