<?php
declare(strict_types=1);

require __DIR__ . '/php_api_common.php';

// Step 5 (extra data source): cache QQ homepage initial data (many playlists/songs).
// Modes:
// - mode=sample: parse local fiddler export file (default)
// - mode=live: fetch https://y.qq.com/ and parse window.__INITIAL_DATA__
//
// Output:
// - MySQL: music_home_items + music_song_cache (for cover cache)
// - Redis: home:qq:index

const QQ_HOME_SAMPLE_PATH = __DIR__ . '/qq/首页信息抓包.txt';
const QQ_COOKIE_PATH = __DIR__ . '/qq/cookie';

function read_cookie_file(string $path): string
{
    if (!is_file($path)) {
        throw new RuntimeException('Cookie file not found: ' . $path);
    }
    return trim((string) file_get_contents($path));
}

function qq_album_cover_url(string $albumMid): string
{
    $albumMid = trim($albumMid);
    if ($albumMid === '')
        return '';
    return 'https://y.gtimg.cn/music/photo_new/T002R500x500M000' . rawurlencode($albumMid) . '.jpg';
}

function qq_share_song_url(string $songMid): string
{
    $songMid = trim($songMid);
    if ($songMid === '')
        return '';
    return 'https://y.qq.com/n/ryqq/songDetail/' . rawurlencode($songMid);
}

function qq_share_playlist_url(string $dissId): string
{
    $dissId = trim($dissId);
    if ($dissId === '')
        return '';
    return 'https://y.qq.com/n/ryqq/playlist/' . rawurlencode($dissId);
}

function extract_between(string $haystack, string $startTag): string
{
    $i = strpos($haystack, $startTag);
    if ($i === false) {
        throw new RuntimeException('Tag not found: ' . $startTag);
    }
    $j = strpos($haystack, '{', $i);
    if ($j === false) {
        throw new RuntimeException('JSON start not found');
    }

    $depth = 0;
    $inStr = false;
    $esc = false;
    $end = null;
    $len = strlen($haystack);
    for ($k = $j; $k < $len; $k++) {
        $ch = $haystack[$k];
        if ($inStr) {
            if ($esc) {
                $esc = false;
            } elseif ($ch === '\\') {
                $esc = true;
            } elseif ($ch === '"') {
                $inStr = false;
            }
            continue;
        }

        if ($ch === '"') {
            $inStr = true;
            continue;
        }
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0 && $k > $j) {
                $end = $k + 1;
                break;
            }
        }
    }
    if ($end === null) {
        throw new RuntimeException('JSON end not found');
    }
    return substr($haystack, $j, $end - $j);
}

function parse_qq_home_initial_data_from_html(string $html): array
{
    $tag = 'window.__INITIAL_DATA__ =';
    $jsObj = extract_between($html, $tag);

    // JS object -> JSON-ish cleanup.
    $jsObj = str_replace(
        [':undefined', ',undefined', 'undefined,', 'undefined}', 'undefined]'],
        [':null', ',null', 'null,', 'null}', 'null]'],
        $jsObj
    );

    $data = json_decode($jsObj, true);
    if (!is_array($data)) {
        // UTF-8 issues: convert from GB18030 (common for Windows exports)
        $converted = @iconv('GB18030', 'UTF-8//IGNORE', $jsObj);
        if (is_string($converted) && $converted !== '') {
            $data = json_decode($converted, true);
        }
    }
    if (!is_array($data)) {
        throw new RuntimeException('Failed to decode window.__INITIAL_DATA__');
    }
    return $data;
}

function parse_qq_home_from_capture_file(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Sample not found: ' . $path);
    }
    $raw = (string) file_get_contents($path);
    // Find the HTML response containing the initial data.
    $needle = 'window.__INITIAL_DATA__ =';
    $pos = strpos($raw, $needle);
    if ($pos === false) {
        // Attempt charset conversion for the whole file, then search.
        $raw2 = @iconv('GB18030', 'UTF-8//IGNORE', $raw);
        if (is_string($raw2) && $raw2 !== '') {
            $raw = $raw2;
            $pos = strpos($raw, $needle);
        }
    }
    if ($pos === false) {
        throw new RuntimeException('window.__INITIAL_DATA__ not found in capture');
    }
    // Use a window around the match to keep parsing fast.
    $slice = substr($raw, max(0, $pos - 20000), 2000000);
    return parse_qq_home_initial_data_from_html($slice);
}

function http_get(string $url, string $cookie = ''): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('ext-curl is required');
    }
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Referer: https://y.qq.com/',
    ]);
    if ($cookie !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP failed: ' . $err);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('HTTP status ' . $status);
    }
    return (string) $resp;
}

function redis_connect(): Redis
{
    if (!class_exists('Redis')) {
        throw new RuntimeException('ext-redis is required');
    }
    $host = env_get('REDIS_HOST', '127.0.0.1');
    $port = (int) env_get('REDIS_PORT', '6379');
    $db = (int) env_get('REDIS_DB', '0');
    $pass = env_get('REDIS_PASS', '');
    $r = new Redis();
    if (!$r->connect($host, $port, 3.0)) {
        throw new RuntimeException('Redis connect failed');
    }
    if ($pass !== '' && !$r->auth($pass)) {
        throw new RuntimeException('Redis auth failed');
    }
    if ($db !== 0 && !$r->select($db)) {
        throw new RuntimeException('Redis select failed');
    }
    return $r;
}

function upsert_home_item(PDO $pdo, array $row): void
{
    $sql = 'INSERT INTO music_home_items (source, section, item_type, item_id, title, subtitle, metric, original_share_url, original_cover_url, hosted_cover_url, extra_json) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?) '
        . 'ON DUPLICATE KEY UPDATE title=VALUES(title), subtitle=VALUES(subtitle), metric=VALUES(metric), original_share_url=VALUES(original_share_url), original_cover_url=VALUES(original_cover_url), hosted_cover_url=VALUES(hosted_cover_url), extra_json=VALUES(extra_json)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $row['source'],
        $row['section'],
        $row['item_type'],
        $row['item_id'],
        $row['title'],
        $row['subtitle'],
        $row['metric'],
        $row['original_share_url'],
        $row['original_cover_url'],
        $row['hosted_cover_url'],
        $row['extra_json'],
    ]);
}

function main(): void
{
    $mode = strtolower(api_param('mode', env_get('MODE', 'sample'))); // sample|live
    $dryRun = api_param('dry_run', env_get('DRY_RUN', '0')) === '1';
    $ttl = (int) api_param('redis_ttl', env_get('REDIS_TTL', '900'));
    $doUpload = api_param('upload', env_get('HOME_UPLOAD', '0')) === '1';
    $tmpDir = api_param('tmp_dir', env_get('TMP_DIR', sys_get_temp_dir()));
    $rr = api_param('upload_rr', env_get('UPLOAD_RR', 'jike'));

    try {
        if ($mode !== 'sample' && $mode !== 'live') {
            throw new RuntimeException('mode must be sample|live');
        }

        if ($mode === 'sample') {
            $samplePath = api_param('sample_path', env_get('QQ_HOME_SAMPLE_PATH', QQ_HOME_SAMPLE_PATH));
            $initial = parse_qq_home_from_capture_file($samplePath);
        } else {
            $cookie = read_cookie_file(env_get('QQ_COOKIE_PATH', QQ_COOKIE_PATH));
            $html = http_get('https://y.qq.com/', $cookie);
            $initial = parse_qq_home_initial_data_from_html($html);
        }

        $sections = [];
        foreach (['hotRecommend', 'hotCategory', 'newLanList', 'newSonglist'] as $k) {
            $sections[$k] = isset($initial[$k]) && is_array($initial[$k]) ? $initial[$k] : [];
        }

        if ($dryRun) {
            api_json(200, 'dry_run_ok', [
                'mode' => $mode,
                'counts' => [
                    'hotRecommend' => count($sections['hotRecommend']),
                    'hotCategory' => count($sections['hotCategory']),
                    'newLanList' => count($sections['newLanList']),
                    'newSonglist' => count($sections['newSonglist']),
                ],
            ]);
            return;
        }

        $pdo = pdo_connect_from_env();
        mysql_migrate_song_cache($pdo);
        mysql_migrate_home_items($pdo);

        // Keep MySQL as a "latest snapshot" for homepage fallback.
        // Cover caching is preserved via music_song_cache and chart caches.
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare("DELETE FROM music_home_items WHERE source=? AND section NOT IN ('matchedFeed')");
            $del->execute(['qq']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $redis = redis_connect();

        $out = [
            'generated_at' => gmdate('c'),
            'source' => 'qq',
            'sections' => [
                'hotRecommend' => [],
                'newSonglist' => [],
                'hotCategory' => [],
                'newLanList' => [],
            ],
        ];

        // hotRecommend playlists
        foreach ($sections['hotRecommend'] as $it) {
            if (!is_array($it))
                continue;
            $dissid = isset($it['dissid']) ? (string) $it['dissid'] : '';
            $title = isset($it['dissname']) ? (string) $it['dissname'] : '';
            $cover = isset($it['imgurl']) ? (string) $it['imgurl'] : '';
            $metric = isset($it['listennum']) ? (int) $it['listennum'] : 0;
            $share = qq_share_playlist_url($dissid);
            if ($dissid === '' || $title === '' || $share === '')
                continue;

            $hosted = mysql_get_hosted_cover($pdo, 'qq', $share);
            if ($hosted === '' && $doUpload && $cover !== '') {
                $tmpFile = download_to_temp($cover, $tmpDir);
                try {
                    $hosted = upload_to_image_host($tmpFile, $rr);
                } finally {
                    @unlink($tmpFile);
                }
                if ($hosted !== '') {
                    mysql_upsert_cover($pdo, 'qq', $share, $cover, $hosted);
                }
            }

            $row = [
                'source' => 'qq',
                'section' => 'hotRecommend',
                'item_type' => 'playlist',
                'item_id' => $dissid,
                'title' => $title,
                'subtitle' => '',
                'metric' => $metric,
                'original_share_url' => $share,
                'original_cover_url' => $cover,
                'hosted_cover_url' => $hosted,
                'extra_json' => json_encode($it, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            upsert_home_item($pdo, $row);

            $out['sections']['hotRecommend'][] = [
                'type' => 'playlist',
                'id' => $dissid,
                'title' => $title,
                'play_count' => $metric,
                'share_url' => $share,
                'cover_url' => normalize_cover_url($hosted, $cover),
            ];
        }

        // newSonglist songs
        foreach ($sections['newSonglist'] as $it) {
            if (!is_array($it))
                continue;
            $mid = isset($it['mid']) ? (string) $it['mid'] : '';
            $title = isset($it['title']) ? (string) $it['title'] : (isset($it['name']) ? (string) $it['name'] : '');
            $album = isset($it['album']) && is_array($it['album']) ? $it['album'] : [];
            $albumMid = isset($album['mid']) ? (string) $album['mid'] : '';
            $cover = $albumMid !== '' ? qq_album_cover_url($albumMid) : '';
            $share = qq_share_song_url($mid);
            if ($mid === '' || $title === '' || $share === '')
                continue;

            $artists = [];
            if (isset($it['singer']) && is_array($it['singer'])) {
                foreach ($it['singer'] as $s) {
                    if (is_array($s) && isset($s['name'])) {
                        $artists[] = (string) $s['name'];
                    }
                }
            }
            $artist = implode(', ', array_values(array_filter(array_map('trim', $artists), static fn($x) => $x !== '')));

            $hosted = $cover !== '' ? mysql_get_hosted_cover($pdo, 'qq', $share) : '';
            if ($hosted === '' && $doUpload && $cover !== '') {
                $tmpFile = download_to_temp($cover, $tmpDir);
                try {
                    $hosted = upload_to_image_host($tmpFile, $rr);
                } finally {
                    @unlink($tmpFile);
                }
                if ($hosted !== '') {
                    mysql_upsert_cover($pdo, 'qq', $share, $cover, $hosted);
                }
            }

            $row = [
                'source' => 'qq',
                'section' => 'newSonglist',
                'item_type' => 'song',
                'item_id' => $mid,
                'title' => $title,
                'subtitle' => $artist,
                'metric' => 0,
                'original_share_url' => $share,
                'original_cover_url' => $cover,
                'hosted_cover_url' => $hosted,
                'extra_json' => json_encode($it, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            upsert_home_item($pdo, $row);

            $out['sections']['newSonglist'][] = [
                'type' => 'song',
                'mid' => $mid,
                'title' => $title,
                'artist' => $artist,
                'share_url' => $share,
                'cover_url' => normalize_cover_url($hosted, $cover),
            ];
        }

        // tags
        foreach ($sections['hotCategory'] as $it) {
            if (!is_array($it))
                continue;
            $id = isset($it['id']) ? (string) $it['id'] : '';
            $name = isset($it['name']) ? (string) $it['name'] : '';
            if ($id === '' || $name === '')
                continue;
            $share = 'qq://category/' . rawurlencode($id);
            $row = [
                'source' => 'qq',
                'section' => 'hotCategory',
                'item_type' => 'tag',
                'item_id' => $id,
                'title' => $name,
                'subtitle' => '',
                'metric' => 0,
                'original_share_url' => $share,
                'original_cover_url' => '',
                'hosted_cover_url' => '',
                'extra_json' => json_encode($it, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            upsert_home_item($pdo, $row);
            $out['sections']['hotCategory'][] = ['id' => $id, 'name' => $name];
        }
        foreach ($sections['newLanList'] as $it) {
            if (!is_array($it))
                continue;
            $id = isset($it['id']) ? (string) $it['id'] : '';
            $name = isset($it['name']) ? (string) $it['name'] : '';
            if ($id === '' || $name === '')
                continue;
            $share = 'qq://newLan/' . rawurlencode($id);
            $row = [
                'source' => 'qq',
                'section' => 'newLanList',
                'item_type' => 'tag',
                'item_id' => $id,
                'title' => $name,
                'subtitle' => '',
                'metric' => 0,
                'original_share_url' => $share,
                'original_cover_url' => '',
                'hosted_cover_url' => '',
                'extra_json' => json_encode($it, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            upsert_home_item($pdo, $row);
            $out['sections']['newLanList'][] = ['id' => $id, 'name' => $name];
        }

        $key = 'home:qq:index';
        $payload = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RuntimeException('json_encode failed');
        }
        if ($ttl > 0) {
            $redis->setex($key, $ttl, $payload);
        } else {
            $redis->set($key, $payload);
        }

        api_json(200, 'ok', [
            'mode' => $mode,
            'redis_key' => $key,
            'counts' => [
                'hotRecommend' => count($out['sections']['hotRecommend']),
                'newSonglist' => count($out['sections']['newSonglist']),
                'hotCategory' => count($out['sections']['hotCategory']),
                'newLanList' => count($out['sections']['newLanList']),
            ],
        ]);
    } catch (Throwable $e) {
        api_json(500, $e->getMessage(), ['mode' => $mode]);
    }
}

main();
