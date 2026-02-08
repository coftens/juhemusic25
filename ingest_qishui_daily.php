<?php
require 'php_api_common.php';

if (PHP_SAPI !== 'cli') {
    die("CLI only");
}

echo "=== Qishui Music Daily Feed Ingest ===\n";

$pdo = pdo_connect_from_env();
mysql_migrate_home_items($pdo);

// 1. Fetch Feed from Qishui API (assuming service runs on 8372)
$apiUrl = 'http://127.0.0.1:8372/feed?count=30';
echo "Fetching feed from $apiUrl ...\n";

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) {
    die("Error: Qishui API returned status $code. Is qishui_api.py running?\n");
}

$json = json_decode((string)$resp, true);
$tracks = $json['data'] ?? [];

if (empty($tracks)) {
    die("Error: Received empty feed from Qishui.\n");
}

echo "Found " . count($tracks) . " tracks.\n";

// 2. Update Database
echo "Updating database (section: qishuiDaily)...\n";

$pdo->exec("DELETE FROM music_home_items WHERE source='qishui' AND section='qishuiDaily'");

$stmt = $pdo->prepare("INSERT INTO music_home_items (source, section, item_type, item_id, title, subtitle, metric, original_share_url, original_cover_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

$count = 0;
foreach ($tracks as $t) {
    $tid = (string)($t['track_id'] ?? '');
    $name = (string)($t['name'] ?? '');
    if (!$tid || !$name) continue;

    $artist = (string)($t['artist'] ?? '');
    $cover = (string)($t['cover'] ?? '');
    $share = (string)($t['share_link'] ?? '');
    
    // Store in a dedicated section for the top card
    $stmt->execute(['qishui', 'qishuiDaily', 'song', $tid, $name, $artist, 0, $share, $cover]);
    $count++;
}

echo "SUCCESS! Ingested $count Qishui daily tracks.\n";

// 3. Clear Redis
if (class_exists('Redis')) {
    try {
        $host = env_get('REDIS_HOST', '127.0.0.1');
        $port = (int)env_get('REDIS_PORT', '6379');
        $redis = new Redis();
        if ($redis->connect($host, $port)) {
            $redis->del('home:qq:index');
            echo "Cleared Redis cache.\n";
        }
    } catch (Throwable $e) {}
}

