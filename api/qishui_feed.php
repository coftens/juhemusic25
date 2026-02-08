<?php
// Ensure JSON response even on errors
header('Content-Type: application/json');

try {
    require '../php_api_common.php';

    $pdo = pdo_connect_from_env();

    // Get matched songs from DB
    $limit = isset($_GET['count']) ? (int) $_GET['count'] : 15;
    if ($limit < 1)
        $limit = 1;
    if ($limit > 100)
        $limit = 100;

    // RAND() keeps the app's infinite flow feeling truly infinite even with small matched pool
    // Note: LIMIT doesn't support parameter binding in MySQL, so we use validated integer directly
    $stmt = $pdo->prepare("SELECT source, item_id, title, subtitle, original_share_url, original_cover_url 
                           FROM music_home_items 
                           WHERE section='matchedFeed' 
                           ORDER BY RAND() LIMIT " . $limit);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $list = [];
    foreach ($rows as $r) {
        // 还原歌手信息：去掉 |QS| 及其后面的原始汽水信息
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

    echo json_encode(['code' => 200, 'msg' => 'ok', 'data' => $list]);

} catch (Throwable $e) {
    // Return empty list instead of error to prevent breaking the app
    // Log the error for debugging
    error_log("Qishui Feed Error: " . $e->getMessage());
    echo json_encode(['code' => 200, 'msg' => 'ok (empty)', 'data' => []]);
}