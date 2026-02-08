<?php
require __DIR__ . '/../php_api_common.php';

try {
    $pdo = pdo_connect_from_env();

    // Query all playlists, prioritizing hotRecommend
    // We select items where item_type is 'playlist'
    $stmt = $pdo->prepare("
        SELECT source, item_id, title, original_cover_url, hosted_cover_url, metric 
        FROM music_home_items 
        WHERE item_type = 'playlist' AND section = 'hotRecommend'
        ORDER BY id DESC 
        LIMIT 100
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $list = [];
    foreach ($rows as $r) {
        $cover = normalize_cover_url(
            (string)($r['hosted_cover_url'] ?? ''),
            (string)($r['original_cover_url'] ?? '')
        );
        $list[] = [
            'source' => $r['source'],
            'id' => $r['item_id'],
            'title' => $r['title'],
            'cover_url' => $cover,
            'play_count' => (int) $r['metric'],
        ];
    }

    header('Content-Type: application/json');
    echo json_encode(['code' => 200, 'msg' => 'ok', 'data' => $list]);

} catch (Throwable $e) {
    header('Content-Type: application/json');
    echo json_encode(['code' => 500, 'msg' => 'Server Error: ' . $e->getMessage()]);
}
