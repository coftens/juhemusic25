<?php
declare(strict_types=1);

require __DIR__ . '/php_api_common.php';

// GET /home.php
// Returns cached homepage data.
// Primary source: Redis key home:qq:index
// Fallback: MySQL music_home_items

function redis_connect_simple(): Redis {
    if (!class_exists('Redis')) {
        throw new RuntimeException('ext-redis is required');
    }
    $host = env_get('REDIS_HOST', '127.0.0.1');
    $port = (int)env_get('REDIS_PORT', '6379');
    $db = (int)env_get('REDIS_DB', '0');
    $pass = env_get('REDIS_PASS', '');
    $r = new Redis();
    if (!$r->connect($host, $port, 3.0)) {
        throw new RuntimeException('Redis connect failed');
    }
    if ($pass !== '' && !$r->auth($pass)) {
        throw new RuntimeException('Redis auth failed');
    }
    if ($db !== 0 && !$r->select($db)) {
        throw new RuntimeException('Redis select failed');
    }
    return $r;
}

try {
    $key = api_param('key', env_get('HOME_REDIS_KEY', 'home:qq:index'));
    $prefer = api_param('prefer', 'redis'); // redis|mysql

    if ($prefer !== 'mysql') {
        $redis = redis_connect_simple();
        $raw = $redis->get($key);
        if (is_string($raw) && $raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                api_json(200, 'ok', $json);
                exit;
            }
        }
    }

    $pdo = pdo_connect_from_env();
    mysql_migrate_home_items($pdo);

    $rows = $pdo->query('SELECT source, section, item_type, item_id, title, subtitle, metric, original_share_url, original_cover_url, hosted_cover_url, extra_json FROM music_home_items ORDER BY section, id DESC')->fetchAll();
    $sections = [
        'hotRecommend' => [],
        'newSonglist' => [],
        'hotCategory' => [],
        'newLanList' => [],
    ];
    foreach ($rows as $r) {
        $section = (string)$r['section'];
        $type = (string)$r['item_type'];
        $src = (string)$r['source'];
        $cover = normalize_cover_url(
            (string)($r['hosted_cover_url'] ?? ''),
            (string)($r['original_cover_url'] ?? '')
        );
        if (!isset($sections[$section])) {
            $sections[$section] = [];
        }

        if ($section === 'hotRecommend') {
            $sections[$section][] = [
                'type' => $type,
                'source' => $src,
                'id' => (string)$r['item_id'],
                'title' => (string)$r['title'],
                'play_count' => (int)($r['metric'] ?? 0),
                'share_url' => (string)$r['original_share_url'],
                'cover_url' => $cover,
            ];
        } elseif ($section === 'newSonglist') {
            $sections[$section][] = [
                'type' => $type,
                'source' => $src,
                'mid' => (string)$r['item_id'],
                'title' => (string)$r['title'],
                'artist' => (string)($r['subtitle'] ?? ''),
                'share_url' => (string)$r['original_share_url'],
                'cover_url' => $cover,
            ];
        } else {
            $sections[$section][] = [
                'id' => (string)$r['item_id'],
                'name' => (string)$r['title'],
            ];
        }
    }

    shuffle($sections['hotRecommend']);
    shuffle($sections['newSonglist']);

    api_json(200, 'ok', [
        'generated_at' => gmdate('c'),
        'source' => 'mixed',
        'sections' => $sections,
    ]);
} catch (Throwable $e) {
    api_json(500, $e->getMessage());
}
