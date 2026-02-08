<?php
declare(strict_types=1);

require __DIR__ . '/../php_api_common.php';

api_require_method('POST');

try {
    $pdo = pdo_connect_from_env();
    mysql_migrate_user_access_tokens($pdo);
    mysql_migrate_listening_daily($pdo);
    $u = api_require_user($pdo);
    $userId = (int)$u['user_id'];

    $body = api_read_json_body();
    $sec = (int)($body['delta_seconds'] ?? 0);
    if ($sec < 0) $sec = 0;
    if ($sec > 60) $sec = 60;
    if ($sec <= 0) {
        api_json(200, 'ok');
        exit;
    }

    $day = gmdate('Y-m-d');
    $sql = 'INSERT INTO music_listening_daily (user_id, day, seconds) VALUES (?,?,?) '
        . 'ON DUPLICATE KEY UPDATE seconds=LEAST(2147483647, seconds + VALUES(seconds))';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $day, $sec]);
    api_json(200, 'ok');
} catch (Throwable $e) {
    api_json(500, $e->getMessage());
}
