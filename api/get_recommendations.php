<?php
declare(strict_types=1);

require __DIR__ . '/../php_api_common.php';

// GET /api/get_recommendations.php?song_id=...&source=wyy|qq|qishui
// Returns 5-10 recommended songs.

function json_out(int $code, $data): void
{
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'code' => $code,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function str_first_non_empty(string ...$vals): string
{
    foreach ($vals as $v) {
        if ($v !== '')
            return $v;
    }
    return '';
}

function extract_wyy_id(string $songId): string
{
    if (preg_match('/\bid=(\d+)\b/', $songId, $m)) {
        return $m[1];
    }
    if (preg_match('/\b(\d{3,})\b/', $songId, $m)) {
        return $m[1];
    }
    return $songId;
}

function extract_qq_mid(string $shareUrlOrId): string
{
    if (preg_match('/songDetail\/([0-9A-Za-z]+)\b/', $shareUrlOrId, $m)) {
        return $m[1];
    }
    if (preg_match('/\b([0-9A-Za-z]{8,})\b/', $shareUrlOrId, $m)) {
        return $m[1];
    }
    return $shareUrlOrId;
}

try {
    $songId = trim(api_param('song_id', ''));
    $source = strtolower(trim(api_param('source', '')));

    if ($songId === '') {
        json_out(400, []);
        exit;
    }
    if (!in_array($source, ['wyy', 'qq', 'qishui'], true)) {
        json_out(400, []);
        exit;
    }

    $out = [];

    $fillFromQq = function (int $need) use (&$out): void {
        if ($need <= 0)
            return;
        $pdo = pdo_connect_from_env();
        mysql_migrate_charts($pdo);
        $stmt = $pdo->prepare(
            'SELECT title, artist, original_share_url, original_cover_url, hosted_cover_url '
            . 'FROM music_charts WHERE source = ? ORDER BY RAND() LIMIT ' . (int) $need
        );
        $stmt->execute(['qq']);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            $share = (string) ($r['original_share_url'] ?? '');
            $mid = extract_qq_mid($share);
            $cover = normalize_cover_url(
                (string) ($r['hosted_cover_url'] ?? ''),
                (string) ($r['original_cover_url'] ?? '')
            );
            $out[] = [
                'id' => $mid !== '' ? $mid : ($share !== '' ? md5($share) : ''),
                'name' => (string) ($r['title'] ?? ''),
                'artist' => (string) ($r['artist'] ?? ''),
                'cover' => $cover,
                'source' => 'qq',
            ];
            if (count($out) >= 10)
                return;
        }
    };

    if ($source === 'wyy') {
        try {
            $cookieFile = __DIR__ . '/../wyy/cookie';
            $cookie = is_file($cookieFile) ? trim((string) file_get_contents($cookieFile)) : '';
            $realId = extract_wyy_id($songId);

            // NeteaseCloudMusicApi: /simi/song?id=...&cookie=...
            $res = http_get_json('http://127.0.0.1:3000/simi/song', [
                'id' => $realId,
                'cookie' => $cookie,
                'limit' => 10,
            ]);

            if (isset($res['songs']) && is_array($res['songs'])) {
                foreach ($res['songs'] as $s) {
                    if (!is_array($s))
                        continue;
                    $id = isset($s['id']) ? (string) $s['id'] : '';
                    if ($id === '')
                        continue;
                    $name = isset($s['name']) ? (string) $s['name'] : '';
                    $artists = '';
                    if (isset($s['artists']) && is_array($s['artists'])) {
                        $names = [];
                        foreach ($s['artists'] as $a) {
                            if (is_array($a) && isset($a['name']) && (string) $a['name'] !== '') {
                                $names[] = (string) $a['name'];
                            }
                        }
                        $artists = implode('/', $names);
                    }
                    $album = (isset($s['album']) && is_array($s['album'])) ? $s['album'] : [];
                    $cover = is_array($album) ? (string) ($album['picUrl'] ?? '') : '';

                    $out[] = [
                        'id' => $id,
                        'name' => $name,
                        'artist' => $artists,
                        'cover' => $cover,
                        'source' => 'wyy',
                    ];
                    if (count($out) >= 10)
                        break;
                }
            }
        } catch (Throwable $e) {
            // Netease API unavailable, will fallback to QQ charts
        }

        if (count($out) < 5) {
            $fillFromQq(5 - count($out));
        }
    }

    if ($source !== 'wyy') {
        // qq/qishui: always inject qq chart songs.
        $fillFromQq(10);
    }

    // Return 5-10 items.
    if (count($out) > 10)
        $out = array_slice($out, 0, 10);
    json_out(200, $out);
} catch (Throwable $e) {
    json_out(500, []);
}
