<?php
declare(strict_types=1);

require __DIR__ . '/../php_api_common.php';

try {
    $pdo = pdo_connect_from_env();
    mysql_migrate_user_access_tokens($pdo);
    mysql_migrate_user_favorites($pdo);
    $u = api_require_user($pdo);
    $userId = (int)$u['user_id'];

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        $stmt = $pdo->prepare('SELECT platform, share_url, name, artist, cover_url, created_at FROM music_user_favorites WHERE user_id=? ORDER BY created_at DESC LIMIT 200');
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'platform' => (string)($r['platform'] ?? ''),
                'share_url' => (string)($r['share_url'] ?? ''),
                'name' => (string)($r['name'] ?? ''),
                'artist' => (string)($r['artist'] ?? ''),
                'cover_url' => (string)($r['cover_url'] ?? ''),
                'created_at' => (string)($r['created_at'] ?? ''),
            ];
        }
        api_json(200, 'ok', ['list' => $out]);
        exit;
    }

    if ($method === 'POST') {
        $body = api_read_json_body();
        $platform = trim((string)($body['platform'] ?? ''));
        $shareUrl = trim((string)($body['share_url'] ?? ''));
        $name = trim((string)($body['name'] ?? ''));
        $artist = trim((string)($body['artist'] ?? ''));
        $coverUrl = trim((string)($body['cover_url'] ?? ''));
        if ($platform === '' || $shareUrl === '' || $name === '') {
            api_json(400, 'missing fields');
            exit;
        }
        $hash = md5($platform . '|' . $shareUrl);
        $sql = 'INSERT INTO music_user_favorites (user_id, share_hash, platform, share_url, name, artist, cover_url) VALUES (?,?,?,?,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE name=VALUES(name), artist=VALUES(artist), cover_url=VALUES(cover_url)';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $hash, $platform, $shareUrl, $name, $artist, $coverUrl]);
        api_json(200, 'ok');
        exit;
    }

    if ($method === 'DELETE') {
        $shareUrl = trim(api_param('share_url', ''));
        $platform = trim(api_param('platform', ''));
        if ($shareUrl === '' || $platform === '') {
            $body = api_read_json_body();
            $shareUrl = trim((string)($body['share_url'] ?? ''));
            $platform = trim((string)($body['platform'] ?? ''));
        }
        if ($shareUrl === '' || $platform === '') {
            api_json(400, 'missing fields');
            exit;
        }
        $hash = md5($platform . '|' . $shareUrl);
        $stmt = $pdo->prepare('DELETE FROM music_user_favorites WHERE user_id=? AND share_hash=?');
        $stmt->execute([$userId, $hash]);
        api_json(200, 'ok');
        exit;
    }

    api_json(405, 'method not allowed');
} catch (Throwable $e) {
    api_json(500, $e->getMessage());
}
