<?php
declare(strict_types=1);

require __DIR__ . '/php_api_common.php';

// GET /search.php?keyword=...&platform=all|qq|wyy&limit=20
// This aggregates Python microservice results.

try {
    $keyword = trim(api_param('keyword', ''));
    $platform = strtolower(trim(api_param('platform', 'all')));
    $limit = (int)api_param('limit', '20');
    if ($keyword === '') {
        api_json(400, 'missing keyword');
        exit;
    }
    if ($limit < 1) $limit = 1;
    if ($limit > 50) $limit = 50;

    $py = rtrim(env_get('PY_BASE_URL', DEFAULT_PY_BASE_URL), '/');
    $platforms = [];
    if ($platform === 'all' || $platform === '') {
        $platforms = ['qq', 'wyy'];
    } elseif ($platform === 'qq' || $platform === 'wyy') {
        $platforms = [$platform];
    } else {
        api_json(400, 'platform must be all|qq|wyy');
        exit;
    }

    $pdo = null;
    $useDb = env_get('MYSQL_DSN', '') !== '' && env_get('MYSQL_USER', '') !== '';
    if ($useDb) {
        $pdo = pdo_connect_from_env();
        mysql_migrate_song_cache($pdo);
    }

    $out = [];
    foreach ($platforms as $p) {
        $resp = http_get_json($py . '/search', [
            'keyword' => $keyword,
            'platform' => $p,
            'limit' => (string)$limit,
        ]);
        $code = isset($resp['code']) ? (int)$resp['code'] : 500;
        if ($code !== 200) {
            throw new RuntimeException('python search failed: ' . (string)($resp['msg'] ?? 'unknown'));
        }
        $data = $resp['data'] ?? null;
        $list = is_array($data) && isset($data['list']) && is_array($data['list']) ? $data['list'] : [];

        foreach ($list as $item) {
            if (!is_array($item)) continue;
            $shareUrl = isset($item['share_url']) ? (string)$item['share_url'] : '';
            $cover = isset($item['cover']) ? (string)$item['cover'] : '';

            $hosted = '';
            if ($pdo instanceof PDO && $shareUrl !== '') {
                $hosted = mysql_get_hosted_cover($pdo, $p, $shareUrl);
                if ($hosted === '') {
                    maybe_async_cover_upload($pdo, $p, $shareUrl, $cover);
                }
            }

            $out[] = [
                'platform' => $p,
                'name' => isset($item['name']) ? (string)$item['name'] : '',
                'artist' => isset($item['artist']) ? (string)$item['artist'] : '',
                'share_url' => $shareUrl,
                'original_cover_url' => $cover,
                'hosted_cover_url' => $hosted,
                'cover_url' => normalize_cover_url($hosted, $cover),
            ];
        }
    }

    api_json(200, 'ok', [
        'keyword' => $keyword,
        'list' => $out,
    ]);
} catch (Throwable $e) {
    api_json(500, $e->getMessage());
}
