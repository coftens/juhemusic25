<?php
require 'php_api_common.php';

if (PHP_SAPI !== 'cli') {
    die("CLI only");
}

echo "=== WYY Daily Songs Ingest ===\n";

$pdo = pdo_connect_from_env();
mysql_migrate_home_items($pdo);

function wyy_request_with_cookie($url) {
    $cookiePath = __DIR__ . '/wyy/cookie';
    if (!file_exists($cookiePath)) {
        echo "Error: wyy/cookie file not found.\n";
        return null;
    }
    $cookie = trim(file_get_contents($cookiePath));
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Referer: https://music.163.com/',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept: application/json',
        'Cookie: ' . $cookie
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($code !== 200) {
        echo "HTTP Error: $code\n";
        return null;
    }
    return json_decode((string)$res, true);
}

// 1. Fetch Daily Songs
echo "Fetching daily songs from Netease...\n";
// API v3 endpoint for daily recommendations
$data = wyy_request_with_cookie('https://music.163.com/api/v3/discovery/recommend/songs');

if (empty($data['data']['dailySongs'])) {
    // Fallback or retry
    echo "v3 API failed or empty, trying v2...\n";
    $data = wyy_request_with_cookie('https://music.163.com/api/discovery/recommend/songs');
}

$songs = $data['data']['dailySongs'] ?? $data['recommend'] ?? [];

if (empty($songs)) {
    die("Error: No daily songs found. Please check if your WYY cookie is valid and not expired.\n");
}

echo "Found " . count($songs) . " daily songs.\n";

// 2. Update Database
echo "Updating database (section: newSonglist)...\n";

// Clear existing WYY songs in newSonglist section to replace with Daily Songs
// Note: We only delete 'newSonglist' for 'wyy', keeping 'hotRecommend' (playlists) intact.
$stmtDel = $pdo->prepare("DELETE FROM music_home_items WHERE source='wyy' AND section='newSonglist'");
$stmtDel->execute();

$stmt = $pdo->prepare("INSERT INTO music_home_items (source, section, item_type, item_id, title, subtitle, metric, original_share_url, original_cover_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

$count = 0;
foreach ($songs as $s) {
    $id = (string)($s['id'] ?? '');
    $title = (string)($s['name'] ?? '');
    if (!$id || !$title) continue;

    $cover = (string)($s['al']['picUrl'] ?? $s['album']['picUrl'] ?? '');
    
    $artists = [];
    $arList = $s['ar'] ?? $s['artists'] ?? [];
    foreach ($arList as $a) { $artists[] = $a['name']; }
    $artistStr = implode('/', $artists);
    
    $reason = $s['reason'] ?? ''; // Recommendation reason (e.g., "Based on your history")
    if ($reason) {
        $artistStr = "[$reason] " . $artistStr;
    }

    $share = "https://music.163.com/song?id=$id";
    
    // We store it in 'newSonglist' so it appears in the song list section of the home page
    $stmt->execute(['wyy', 'newSonglist', 'song', $id, $title, $artistStr, 0, $share, $cover]);
    $count++;
}

echo "SUCCESS! Ingested $count daily recommended songs.\n";

// 3. Clear Redis
if (class_exists('Redis')) {
    try {
        $host = env_get('REDIS_HOST', '127.0.0.1');
        $port = (int)env_get('REDIS_PORT', '6379');
        $redis = new Redis();
        if ($redis->connect($host, $port)) {
            $key = 'home:qq:index';
            $redis->del($key);
            echo "Cleared Redis cache: $key\n";
        }
    } catch (Throwable $e) {}
}

