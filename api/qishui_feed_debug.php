<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

$debug = [];
$debug[] = "Start execution";

try {
    $debug[] = "Loading php_api_common.php";
    require '../php_api_common.php';
    $debug[] = "Loaded OK";

    $debug[] = "Connecting to database";
    $pdo = pdo_connect_from_env();
    $debug[] = "Connected OK";

    $limit = isset($_GET['count']) ? (int) $_GET['count'] : 15;
    if ($limit < 1)
        $limit = 1;
    if ($limit > 100)
        $limit = 100;
    $debug[] = "Limit: $limit";

    $debug[] = "Preparing query";
    $stmt = $pdo->prepare("SELECT source, item_id, title, subtitle, original_share_url, original_cover_url 
                           FROM music_home_items 
                           WHERE section='matchedFeed' 
                           ORDER BY RAND() LIMIT ?");
    $debug[] = "Query prepared";

    $debug[] = "Executing query";
    $stmt->execute([$limit]);
    $debug[] = "Query executed";

    $rows = $stmt->fetchAll();
    $debug[] = "Fetched " . count($rows) . " rows";

    $list = [];
    foreach ($rows as $r) {
        $parts = explode(" |QS| ", (string) $r['subtitle']);
        $cleanArtist = $parts[0];

        $list[] = [
            'platform' => $r['source'],
            'id' => $r['item_id'],
            'name' => $r['title'],
            'artist' => $cleanArtist,
            'cover_url' => $r['original_cover_url'],
            'share_url' => $r['original_share_url'],
        ];
    }
    $debug[] = "Processed " . count($list) . " songs";

    echo json_encode(['code' => 200, 'msg' => 'ok', 'data' => $list, 'debug' => $debug]);

} catch (Throwable $e) {
    echo json_encode([
        'code' => 200,
        'msg' => 'error',
        'data' => [],
        'debug' => $debug,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
}
