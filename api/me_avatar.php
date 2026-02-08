<?php
declare(strict_types=1);

require __DIR__ . '/../php_api_common.php';

api_require_method('POST');

try {
    $pdo = pdo_connect_from_env();
    mysql_migrate_users($pdo);
    mysql_migrate_user_access_tokens($pdo);
    $u = api_require_user($pdo);
    $userId = (int)$u['user_id'];

    if (!isset($_FILES['avatar'])) {
        api_json(400, 'missing file');
        exit;
    }
    $f = $_FILES['avatar'];
    if (!is_array($f) || !isset($f['tmp_name'])) {
        api_json(400, 'invalid upload');
        exit;
    }
    if (!empty($f['error'])) {
        api_json(400, 'upload error');
        exit;
    }
    $tmp = (string)$f['tmp_name'];
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        api_json(400, 'file too large');
        exit;
    }

    $fi = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$fi->file($tmp);
    $ext = '';
    if ($mime === 'image/jpeg') {
        $ext = 'jpg';
    } elseif ($mime === 'image/png') {
        $ext = 'png';
    } elseif ($mime === 'image/webp') {
        $ext = 'webp';
    }
    if ($ext === '') {
        api_json(400, 'unsupported image');
        exit;
    }

    $dir = env_get('AVATAR_DIR', __DIR__ . '/../uploads/avatars');
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir)) {
        api_json(500, 'avatar dir not writable');
        exit;
    }

    $key = 'u' . $userId . '_' . bin2hex(random_bytes(10));
    $fileName = $key . '.' . $ext;
    $dst = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($tmp, $dst)) {
        api_json(500, 'save failed');
        exit;
    }

    $publicPrefix = rtrim(env_get('PUBLIC_BASE_URL', ''), '/');
    $rel = '/uploads/avatars/' . $fileName;
    $avatarUrl = $publicPrefix !== '' ? ($publicPrefix . $rel) : $rel;

    $stmt = $pdo->prepare('UPDATE music_users SET avatar_path=? WHERE id=?');
    $stmt->execute([$avatarUrl, $userId]);

    api_json(200, 'ok', [
        'avatar_url' => $avatarUrl,
    ]);
} catch (Throwable $e) {
    api_json(500, $e->getMessage());
}
