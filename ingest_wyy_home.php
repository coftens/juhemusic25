<?php
require 'php_api_common.php';

if (PHP_SAPI !== 'cli') {
    die("CLI only");
}

echo "=== WYY Home Sync - OMNI MODE ===\n";

$pdo = pdo_connect_from_env();
mysql_migrate_home_items($pdo);

function wyy_request($url)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Referer: https://music.163.com/',
        'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36',
        'Accept: application/json',
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200)
        return null;
    return json_decode((string) $res, true);
}

// --- 抓取歌单 (多路径尝试) ---
$playlists = [];

echo "Attempting Path A (Personalized)...";
$dataA = wyy_request('https://music.163.com/api/personalized?limit=50');
if (!empty($dataA['result'])) {
    foreach ($dataA['result'] as $p) {
        $playlists[] = [
            'id' => $p['id'],
            'name' => $p['name'],
            'pic' => $p['picUrl'],
            'metric' => $p['playCount'] ?? 0
        ];
    }
}

if (count($playlists) < 10) {
    echo "Path A failed or short, attempting Path B (Playlist List)...";
    $dataB = wyy_request('https://music.163.com/api/playlist/list?cat=全部&order=hot&limit=50');
    if (!empty($dataB['playlists'])) {
        foreach ($dataB['playlists'] as $p) {
            $playlists[] = [
                'id' => $p['id'],
                'name' => $p['name'],
                'pic' => $p['coverImgUrl'],
                'metric' => $p['playCount'] ?? 0
            ];
        }
    }
}

// --- 抓取新歌 ---
echo "Fetching new songs...";
$songData = wyy_request('https://music.163.com/api/personalized/newsong?limit=50');
$rawSongs = $songData['result'] ?? [];

echo "Summary: Found " . count($playlists) . " playlists and " . count($rawSongs) . " songs.\n";

if (empty($playlists) && empty($rawSongs)) {
    die("CRITICAL ERROR: All API paths failed. IP might be temporarily blocked by Netease.\n");
}

// --- 写入数据库 ---
echo "Updating database...";
$pdo->exec("DELETE FROM music_home_items WHERE source='wyy' AND section NOT IN ('matchedFeed')");

$stmt = $pdo->prepare("INSERT INTO music_home_items (source, section, item_type, item_id, title, subtitle, metric, original_share_url, original_cover_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

$pCount = 0;
foreach ($playlists as $p) {
    if (empty($p['id']) || empty($p['name']))
        continue;
    $share = "https://music.163.com/playlist?id=" . $p['id'];
    $stmt->execute(['wyy', 'hotRecommend', 'playlist', (string) $p['id'], (string) $p['name'], '', (int) $p['metric'], $share, (string) $p['pic']]);
    $pCount++;
}

$sCount = 0;
foreach ($rawSongs as $it) {
    $s = $it['song'] ?? $it;
    $id = (string) ($s['id'] ?? '');
    $title = (string) ($s['name'] ?? '');
    $cover = (string) ($it['picUrl'] ?? ($s['album']['picUrl'] ?? ''));

    $artists = [];
    $rawArtists = $s['artists'] ?? ($s['ar'] ?? []);
    foreach ($rawArtists as $a) {
        $artists[] = $a['name'];
    }
    $artistStr = implode('/', $artists);

    if ($id && $title) {
        $share = "https://music.163.com/song?id=$id";
        $stmt->execute(['wyy', 'newSonglist', 'song', $id, $title, $artistStr, 0, $share, $cover]);
        $sCount++;
    }
}

echo "SUCCESS! Ingested $pCount playlists and $sCount songs.\n";

// 4. Clear Redis cache to force refresh
if (class_exists('Redis')) {
    try {
        $host = env_get('REDIS_HOST', '127.0.0.1');
        $port = (int) env_get('REDIS_PORT', '6379');
        $pass = env_get('REDIS_PASS', '');
        $db = (int) env_get('REDIS_DB', '0');

        $redis = new Redis();
        if ($redis->connect($host, $port, 2.0)) {
            if ($pass)
                $redis->auth($pass);
            $redis->select($db);
            $key = env_get('HOME_REDIS_KEY', 'home:qq:index');
            $redis->del($key);
            echo "Cleared Redis cache: $key\n";
        }
    } catch (Throwable $e) {
        echo "Redis clear skipped: " . $e->getMessage() . "\n";
    }
}