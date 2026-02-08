<?php
declare(strict_types=1);

require __DIR__ . '/../php_api_common.php';

function require_playlist_owner(PDO $pdo, int $playlistId, int $userId): void {
    $stmt = $pdo->prepare('SELECT id FROM music_user_playlists WHERE id=? AND user_id=? LIMIT 1');
    $stmt->execute([$playlistId, $userId]);
    if (!$stmt->fetch()) {
        api_json(404, 'playlist not found');
        exit;
    }
}

try {
    $pdo = pdo_connect_from_env();
    mysql_migrate_user_access_tokens($pdo);
    mysql_migrate_user_playlists($pdo);
    mysql_migrate_user_playlist_tracks($pdo);
    $u = api_require_user($pdo);
    $userId = (int)$u['user_id'];

    $playlistId = (int)api_param('playlist_id', '0');
    if ($playlistId <= 0) {
        $body = api_read_json_body();
        $playlistId = (int)($body['playlist_id'] ?? 0);
    }
    if ($playlistId <= 0) {
        api_json(400, 'missing playlist_id');
        exit;
    }
    require_playlist_owner($pdo, $playlistId, $userId);

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        $stmt = $pdo->prepare('SELECT platform, share_url, name, artist, cover_url FROM music_user_playlist_tracks WHERE playlist_id=? ORDER BY position ASC, added_at DESC LIMIT 500');
        $stmt->execute([$playlistId]);
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'platform' => (string)($r['platform'] ?? ''),
                'share_url' => (string)($r['share_url'] ?? ''),
                'name' => (string)($r['name'] ?? ''),
                'artist' => (string)($r['artist'] ?? ''),
                'cover_url' => (string)($r['cover_url'] ?? ''),
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
        $position = (int)($body['position'] ?? 0);
        if ($platform === '' || $shareUrl === '' || $name === '') {
            api_json(400, 'missing fields');
            exit;
        }
        $hash = md5($platform . '|' . $shareUrl);
        $sql = 'INSERT INTO music_user_playlist_tracks (playlist_id, share_hash, platform, share_url, name, artist, cover_url, position) VALUES (?,?,?,?,?,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE name=VALUES(name), artist=VALUES(artist), cover_url=VALUES(cover_url), position=VALUES(position)';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$playlistId, $hash, $platform, $shareUrl, $name, $artist, $coverUrl, $position]);
        // bump playlist updated_at
        $pdo->prepare('UPDATE music_user_playlists SET updated_at=NOW() WHERE id=?')->execute([$playlistId]);
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
        $stmt = $pdo->prepare('DELETE FROM music_user_playlist_tracks WHERE playlist_id=? AND share_hash=?');
        $stmt->execute([$playlistId, $hash]);
        $pdo->prepare('UPDATE music_user_playlists SET updated_at=NOW() WHERE id=?')->execute([$playlistId]);
        api_json(200, 'ok');
        exit;
    }

    api_json(405, 'method not allowed');
} catch (Throwable $e) {
    api_json(500, $e->getMessage());
}
