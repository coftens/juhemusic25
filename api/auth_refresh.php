<?php
declare(strict_types=1);

require __DIR__ . '/../php_api_common.php';

api_require_method('POST');

try {
    $body = api_read_json_body();
    $refresh = trim((string)($body['refresh_token'] ?? ''));
    $deviceId = trim((string)($body['device_id'] ?? ''));
    if ($refresh === '') {
        api_json(400, 'missing refresh_token');
        exit;
    }

    $pdo = pdo_connect_from_env();
    mysql_migrate_user_access_tokens($pdo);
    mysql_migrate_user_refresh_tokens($pdo);

    $tokens = mysql_auth_refresh_rotate($pdo, $refresh, $deviceId);
    if ($tokens === null) {
        api_json(401, 'invalid refresh_token');
        exit;
    }

    api_json(200, 'ok', [
        'tokens' => [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'access_expires_in' => $tokens['access_expires_in'],
            'refresh_expires_in' => $tokens['refresh_expires_in'],
        ],
    ]);
} catch (Throwable $e) {
    api_json(500, $e->getMessage());
}
