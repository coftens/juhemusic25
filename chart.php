<?php
declare(strict_types=1);

require __DIR__ . '/php_api_common.php';

// GET /chart.php?source=qq|wyy|all&type=hot|soaring&limit=1..200
// Returns cached chart list.

function normalize_chart_rows(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $title = (string)($r['title'] ?? '');
        $artist = (string)($r['artist'] ?? '');
        $share = (string)($r['share_url'] ?? ($r['original_share_url'] ?? ''));
        if ($title === '' || $share === '') continue;
        $cover = (string)($r['cover_url'] ?? '');
        if ($cover === '') {
            $cover = normalize_cover_url(
                (string)($r['hosted_cover_url'] ?? ''),
                (string)($r['original_cover_url'] ?? '')
            );
        }
        $out[] = [
            'title' => $title,
            'artist' => $artist,
            'share_url' => $share,
            'cover_url' => $cover,
        ];
    }
    return $out;
}

function merge_chart_lists(array $a, array $b, int $limit): array {
    $seen = [];
    $out = [];
    $max = max(count($a), count($b));
    
    for ($i = 0; $i < $max; $i++) {
        $candidates = [];
        if (isset($a[$i])) $candidates[] = $a[$i];
        if (isset($b[$i])) $candidates[] = $b[$i];
        
        foreach ($candidates as $r) {
            if (!is_array($r)) continue;
            $title = (string)($r['title'] ?? '');
            $artist = (string)($r['artist'] ?? '');
            
            // Deduplicate by Content (Title + Artist)
            $hash = md5(mb_strtolower(trim($title) . '|' . trim($artist)));
            
            if (isset($seen[$hash])) continue;
            $seen[$hash] = 1;
            
            $out[] = $r;
            if (count($out) >= $limit) return $out;
        }
    }
    return $out;
}

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
    $source = strtolower(trim(api_param('source', 'qq')));
    $type = strtolower(trim(api_param('type', 'hot')));
    $limit = (int)api_param('limit', '200');
    if (!in_array($source, ['qq', 'wyy', 'all'], true)) {
        api_json(400, 'source must be qq|wyy|all');
        exit;
    }
    if (!in_array($type, ['hot', 'soaring'], true)) {
        api_json(400, 'type must be hot|soaring');
        exit;
    }
    if ($limit < 1) $limit = 1;
    if ($limit > 200) $limit = 200;

    $redis = redis_connect_simple();
    if ($source !== 'all') {
        $key = 'chart:' . $source . ':' . $type;
        $raw = $redis->get($key);
        if (is_string($raw) && $raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json) && isset($json['list']) && is_array($json['list'])) {
                $rows = normalize_chart_rows(array_slice($json['list'], 0, $limit));
                api_json(200, 'ok', [
                    'source' => $source,
                    'type' => $type,
                    'list' => $rows,
                ]);
                exit;
            }
        }
    } else {
        $rawQq = $redis->get('chart:qq:' . $type);
        $rawWyy = $redis->get('chart:wyy:' . $type);

        $qq = [];
        $wyy = [];
        if (is_string($rawQq) && $rawQq !== '') {
            $j = json_decode($rawQq, true);
            if (is_array($j) && isset($j['list']) && is_array($j['list'])) {
                $qq = normalize_chart_rows($j['list']);
            }
        }
        if (is_string($rawWyy) && $rawWyy !== '') {
            $j = json_decode($rawWyy, true);
            if (is_array($j) && isset($j['list']) && is_array($j['list'])) {
                $wyy = normalize_chart_rows($j['list']);
            }
        }
        if ($qq || $wyy) {
            $merged = merge_chart_lists($qq, $wyy, $limit);
            api_json(200, 'ok', [
                'source' => 'all',
                'type' => $type,
                'list' => $merged,
            ]);
            exit;
        }
    }

    // Fallback to MySQL
    $pdo = pdo_connect_from_env();
    mysql_migrate_charts($pdo);
    $out = [];
    if ($source === 'all') {
        $stmtQq = $pdo->prepare('SELECT title, artist, original_share_url, original_cover_url, hosted_cover_url FROM music_charts WHERE source="qq" AND type=? ORDER BY id ASC LIMIT 200');
        $stmtWyy = $pdo->prepare('SELECT title, artist, original_share_url, original_cover_url, hosted_cover_url FROM music_charts WHERE source="wyy" AND type=? ORDER BY id ASC LIMIT 200');
        $stmtQq->execute([$type]);
        $stmtWyy->execute([$type]);
        $qq = normalize_chart_rows($stmtQq->fetchAll());
        $wyy = normalize_chart_rows($stmtWyy->fetchAll());
        $out = merge_chart_lists($qq, $wyy, $limit);
    } else {
        $stmt = $pdo->prepare('SELECT title, artist, original_share_url, original_cover_url, hosted_cover_url FROM music_charts WHERE source=? AND type=? ORDER BY id ASC LIMIT ' . (int)$limit);
        $stmt->execute([$source, $type]);
        $out = normalize_chart_rows($stmt->fetchAll());
    }
    api_json(200, 'ok', [
        'source' => $source,
        'type' => $type,
        'list' => $out,
    ]);
} catch (Throwable $e) {
    api_json(500, $e->getMessage());
}
