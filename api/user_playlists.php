<?php
declare(strict_types=1);

require __DIR__ . '/../php_api_common.php';

try {
    $pdo = pdo_connect_from_env();
    mysql_migrate_user_access_tokens($pdo);
    mysql_migrate_user_playlists($pdo);
    mysql_migrate_user_playlist_tracks($pdo);
    $u = api_require_user($pdo);
    $userId = (int) $u['user_id'];

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        $stmt = $pdo->prepare('SELECT p.id, p.platform, p.external_id, p.name, p.cover_url, p.created_at, p.updated_at, p.track_count as cached_track_count, (SELECT COUNT(*) FROM music_user_playlist_tracks t WHERE t.playlist_id=p.id) AS track_count FROM music_user_playlists p WHERE p.user_id=? ORDER BY p.updated_at DESC');
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) ($r['id'] ?? 0),
                'platform' => (string) ($r['platform'] ?? 'local'),
                'external_id' => (string) ($r['external_id'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
                'cover_url' => (string) ($r['cover_url'] ?? ''),
                // Local playlist: dynamic count from tracks table
                // External playlist: cached track_count from this table
                'track_count' => ($r['platform'] === 'local') ? (int) ($r['track_count'] ?? 0) : (int) ($r['cached_track_count'] ?? 0),
                'created_at' => (string) ($r['created_at'] ?? ''),
                'updated_at' => (string) ($r['updated_at'] ?? ''),
            ];
        }
        api_json(200, 'ok', ['list' => $out]);
        exit;
    }

    if ($method === 'POST') {
        $body = api_read_json_body();
        $name = trim((string) ($body['name'] ?? ''));
        $platform = trim((string) ($body['platform'] ?? 'local'));
        $externalId = trim((string) ($body['external_id'] ?? ''));
        $coverUrl = trim((string) ($body['cover_url'] ?? ''));
        $trackCount = (int) ($body['track_count'] ?? 0);

        if ($name === '' || strlen($name) > 120) {
            api_json(400, 'invalid name');
            exit;
        }

        if ($platform === 'local') {
            $stmt = $pdo->prepare('INSERT INTO music_user_playlists (user_id, name, platform) VALUES (?,?,?)');
            $stmt->execute([$userId, $name, 'local']);
            $id = (int) $pdo->lastInsertId();
            api_json(200, 'ok', ['id' => $id]);
            exit;
        } else {
            if ($externalId === '') {
                api_json(400, 'missing external_id');
                exit;
            }
            // Favorite external playlist
            $sql = 'INSERT INTO music_user_playlists (user_id, platform, external_id, name, cover_url, track_count) VALUES (?,?,?,?,?,?) '
                . 'ON DUPLICATE KEY UPDATE name=VALUES(name), cover_url=VALUES(cover_url), track_count=VALUES(track_count), updated_at=NOW()';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId, $platform, $externalId, $name, $coverUrl, $trackCount]);
            $id = (int) $pdo->lastInsertId();
            // If duplicate update, lastInsertId might be 0 or incorrect depending on driver, fetch it back.
            if ($id === 0) {
                $stmt2 = $pdo->prepare('SELECT id FROM music_user_playlists WHERE user_id=? AND platform=? AND external_id=? LIMIT 1');
                $stmt2->execute([$userId, $platform, $externalId]);
                $row = $stmt2->fetch();
                if ($row)
                    $id = (int) $row['id'];
            }
            api_json(200, 'ok', ['id' => $id]);
            exit;
        }
    }

    if ($method === 'DELETE') {
        $id = (int) api_param('id', '0');
        $platform = trim(api_param('platform', ''));
        $externalId = trim(api_param('external_id', ''));

        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM music_user_playlists WHERE id=? AND user_id=?');
            $stmt->execute([$id, $userId]);
        } elseif ($platform !== '' && $externalId !== '') {
            $stmt = $pdo->prepare('DELETE FROM music_user_playlists WHERE user_id=? AND platform=? AND external_id=?');
            $stmt->execute([$userId, $platform, $externalId]);
        } else {
            api_json(400, 'missing id or platform/external_id');
            exit;
        }
        api_json(200, 'ok');
        exit;
    }

    api_json(405, 'method not allowed');
} catch (Throwable $e) {
    api_json(500, $e->getMessage());
}
