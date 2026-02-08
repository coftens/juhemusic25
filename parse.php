<?php
declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', '0');

require __DIR__ . '/php_api_common.php';

// GET /parse.php?url=...&quality=standard|exhigh|lossless|hires|jyeffect|sky|jymaster|master|atmos_2|atmos_51
// Proxies to Python /parse and enriches cover urls from DB cache (best-effort, non-blocking).

try {
    $url = trim(api_param('url', ''));
    $quality = strtolower(trim(api_param('quality', 'lossless')));
    if ($url === '') {
        api_json(400, 'missing url');
        exit;
    }
    $allowed = ['standard', 'exhigh', 'lossless', 'hires', 'jyeffect', 'sky', 'jymaster', 'master', 'atmos_2', 'atmos_51'];
    if (!in_array($quality, $allowed, true)) {
        $quality = 'lossless';
    }

    // FORCE internal IP because 127.0.0.1 is blocked/unreachable
    $py = 'http://172.21.28.219:8002';
    
    $resp = http_get_json($py . '/parse', [
        'url' => $url,
        'quality' => $quality,
    ]);
    $code = isset($resp['code']) ? (int)$resp['code'] : 500;
    if ($code !== 200) {
        throw new RuntimeException('python parse failed: ' . (string)($resp['msg'] ?? 'unknown'));
    }
    $data = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : [];
    
    error_log('[ParseDebug] Python返回的data: ' . json_encode($data, JSON_UNESCAPED_UNICODE));

    $platform = isset($data['platform']) ? (string)$data['platform'] : '';
    if ($platform === 'qishui') {
        $source = 'qishui';
        $shareUrl = $url;
        $originalCover = isset($data['cover']) ? (string)$data['cover'] : '';
    } elseif ($platform === 'qq') {
        $source = 'qq';
        $mid = isset($data['mid']) ? (string)$data['mid'] : '';
        $shareUrl = $mid !== '' ? ('https://y.qq.com/n/ryqq/songDetail/' . rawurlencode($mid)) : $url;
        $originalCover = isset($data['cover']) ? (string)$data['cover'] : '';
    } else {
        $source = 'wyy';
        $shareUrl = $url;
        $originalCover = isset($data['cover']) ? (string)$data['cover'] : '';
    }
    
    error_log('[ParseDebug] 提取的originalCover: ' . $originalCover);

    // Try to get hosted cover from cache (non-blocking, no uploads on this path)
    $pdo = null;
    $hosted = '';
    $useDb = env_get('MYSQL_DSN', '') !== '' && env_get('MYSQL_USER', '') !== '';
    if ($useDb) {
        try {
            $pdo = pdo_connect_from_env();
            mysql_migrate_song_cache($pdo);
            $hosted = mysql_get_hosted_cover($pdo, $source, $shareUrl);
            error_log('[ParseDebug] 数据库hosted封面: ' . $hosted);
        } catch (Throwable $e) {
            // ignore db errors
            error_log('[ParseDebug] 数据库查询失败: ' . $e->getMessage());
        }
    }

    $data['source'] = $source;
    $data['original_share_url'] = $shareUrl;
    
    // Normalize cover URLs to filter out Oil hosting
    $finalCover = normalize_cover_url($hosted, $originalCover);
    
    // Always set the field even if empty
    $data['original_cover_url'] = $finalCover;
    $data['hosted_cover_url'] = '';  // Empty since we already normalized

    api_json(200, 'ok', $data);
} catch (Throwable $e) {
    api_json(500, strip_tags($e->getMessage()));
}