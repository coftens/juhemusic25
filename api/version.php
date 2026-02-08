<?php
header('Content-Type: application/json; charset=utf-8');

// 优先从 admin/version_config.json 读取配置
$configFile = __DIR__ . '/../admin/version_config.json';

$config = [
    'version' => '',
    'download_url' => '',
    'force' => false,
    'changelog' => '',
];

if (file_exists($configFile)) {
    $loaded = json_decode(file_get_contents($configFile), true);
    if ($loaded) {
        $config = $loaded;
    }
}

// 返回 JSON
echo json_encode([
    'code' => 200,
    'data' => [
        'version' => $config['version'] ?? '',
        'download_url' => $config['download_url'] ?? '',
        'force' => $config['force'] ?? false,
        'changelog' => $config['changelog'] ?? '',
    ],
], JSON_UNESCAPED_UNICODE);
