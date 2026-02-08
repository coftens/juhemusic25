<?php
declare(strict_types=1);

require __DIR__ . '/../php_api_common.php';

try {
    $pdo = pdo_connect_from_env();
    mysql_migrate_user_access_tokens($pdo);
    mysql_migrate_user_recents($pdo);
    $u = api_require_user($pdo);
    $userId = (int)$u['user_id'];

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        $limit = (int)api_param('limit', '30');
        if ($limit < 1) $limit = 1;
        if ($limit > 100) $limit = 100;
        $stmt = $pdo->prepare('SELECT platform, share_url, name, artist, cover_url, last_played_at FROM music_user_recents WHERE user_id=? ORDER BY last_played_at DESC LIMIT ' . (int)$limit);
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
                'last_played_at' => (string)($r['last_played_at'] ?? ''),
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
        $sql = 'INSERT INTO music_user_recents (user_id, share_hash, platform, share_url, name, artist, cover_url, last_played_at) '
            . 'VALUES (?,?,?,?,?,?,?, NOW()) '
            . 'ON DUPLICATE KEY UPDATE name=VALUES(name), artist=VALUES(artist), cover_url=VALUES(cover_url), last_played_at=NOW()';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $hash, $platform, $shareUrl, $name, $artist, $coverUrl]);
        api_json(200, 'ok');
        exit;
    }

    api_json(405, 'method not allowed');
} catch (Throwable $e) {
    api_json(500, $e->getMessage());
}
