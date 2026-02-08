<?php
declare(strict_types=1);

require __DIR__ . '/../php_api_common.php';

api_require_method('POST');

try {
    $body = api_read_json_body();
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    $deviceId = trim((string)($body['device_id'] ?? ''));

    if ($username === '' || $password === '') {
        api_json(400, 'missing credentials');
        exit;
    }

    $pdo = pdo_connect_from_env();
    mysql_migrate_users($pdo);
    mysql_migrate_user_access_tokens($pdo);
    mysql_migrate_user_refresh_tokens($pdo);

    $stmt = $pdo->prepare('SELECT id, username, password_hash, avatar_path FROM music_users WHERE username=? LIMIT 1');
    $stmt->execute([$username]);
    $u = $stmt->fetch();
    if (!is_array($u) || !isset($u['password_hash'])) {
        api_json(401, 'invalid credentials');
        exit;
    }
    $ok = password_verify($password, (string)$u['password_hash']);
    if (!$ok) {
        api_json(401, 'invalid credentials');
        exit;
    }

    $userId = (int)$u['id'];
    $tokens = mysql_auth_mint_tokens($pdo, $userId, $deviceId);

    $avatarPath = (string)($u['avatar_path'] ?? '');
    $avatarUrl = $avatarPath !== '' ? $avatarPath : '';
    api_json(200, 'ok', [
        'user' => [
            'id' => $userId,
            'username' => (string)($u['username'] ?? ''),
            'avatar_url' => $avatarUrl,
        ],
        'tokens' => $tokens,
    ]);
} catch (Throwable $e) {
    api_json(500, $e->getMessage());
}
