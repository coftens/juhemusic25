<?php
declare(strict_types=1);

require __DIR__ . '/../php_api_common.php';

try {
    $pdo = pdo_connect_from_env();
    mysql_migrate_users($pdo);
    mysql_migrate_user_access_tokens($pdo);
    $u = api_require_user($pdo);

    api_json(200, 'ok', [
        'id' => (int)$u['user_id'],
        'username' => (string)($u['username'] ?? ''),
        'avatar_url' => (string)($u['avatar_path'] ?? ''),
    ]);
} catch (Throwable $e) {
    api_json(500, $e->getMessage());
}
