<?php
declare(strict_types=1);

require __DIR__ . '/../php_api_common.php';

api_require_method('POST');

try {
    $body = api_read_json_body();
    $refresh = trim((string)($body['refresh_token'] ?? ''));
    if ($refresh === '') {
        api_json(200, 'ok');
        exit;
    }
    $hash = auth_hash_token($refresh);

    $pdo = pdo_connect_from_env();
    mysql_migrate_user_refresh_tokens($pdo);
    $stmt = $pdo->prepare('UPDATE music_user_refresh_tokens SET revoked_at=NOW() WHERE token_hash=?');
    $stmt->execute([$hash]);
    api_json(200, 'ok');
} catch (Throwable $e) {
    api_json(500, $e->getMessage());
}
