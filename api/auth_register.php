<?php
declare(strict_types=1);

require __DIR__ . '/../php_api_common.php';

api_require_method('POST');

try {
    $body = api_read_json_body();
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    $deviceId = trim((string)($body['device_id'] ?? ''));

    if ($username === '') {
        api_json(400, 'missing username');
        exit;
    }
    if (strlen($username) < 2 || strlen($username) > 50) {
        api_json(400, 'invalid username');
        exit;
    }
    if (strlen($password) < 10) {
        api_json(400, 'password too short');
        exit;
    }

    $pdo = pdo_connect_from_env();
    mysql_migrate_users($pdo);
    mysql_migrate_user_access_tokens($pdo);
    mysql_migrate_user_refresh_tokens($pdo);

    $hashAlgo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    $pwHash = password_hash($password, $hashAlgo);
    if (!is_string($pwHash) || $pwHash === '') {
        throw new RuntimeException('password_hash failed');
    }

    $pdo->beginTransaction();
    try {
        $stmt2 = $pdo->prepare('SELECT id FROM music_users WHERE username=? LIMIT 1');
        $stmt2->execute([$username]);
        if ($stmt2->fetch()) {
            $pdo->rollBack();
            api_json(400, 'username taken');
            exit;
        }

        $ins = $pdo->prepare('INSERT INTO music_users (username, password_hash) VALUES (?,?)');
        $ins->execute([$username, $pwHash]);
        $userId = (int)$pdo->lastInsertId();
        $tokens = mysql_auth_mint_tokens($pdo, $userId, $deviceId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    api_json(200, 'ok', [
        'user' => [
            'id' => $userId,
            'username' => $username,
            'avatar_url' => '',
        ],
        'tokens' => $tokens,
    ]);
} catch (Throwable $e) {
    api_json(500, $e->getMessage());
}
