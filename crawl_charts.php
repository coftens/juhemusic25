<?php
declare(strict_types=1);

/*
MySQL table (for Step 2 usage):

CREATE TABLE `music_charts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source` varchar(10) NOT NULL COMMENT '枚举值: qq, wyy (汽水暂无榜单)',
  `type` varchar(20) NOT NULL COMMENT '枚举值: hot(热歌), soaring(飙升)',
  `title` varchar(255) NOT NULL COMMENT '歌曲名称',
  `artist` varchar(255) NOT NULL COMMENT '歌手名称',
  `original_share_url` varchar(500) NOT NULL COMMENT '原始网页分享链接，用于后续解析',
  `original_cover_url` text COMMENT '源站封面链接',
  `hosted_cover_url` text COMMENT '上传到我方图床后的链接',
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_source_type` (`source`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
*/

// Step 1 only: crawl QQ + Netease charts into a unified JSON structure.
// Requirements:
// - Read cookies from local files: qq/cookie and wyy/cookie
// - Parsing logic derived from local captures:
//   - wyy/*抓包数据.txt includes <textarea id="song-list-pre-data"> JSON
//   - qq/*抓包数据.txt shows modern endpoints; we implement via musicu.fcg JSON (Option 1)

const QQ_COOKIE_PATH = __DIR__ . '/qq/cookie';
const WYY_COOKIE_PATH = __DIR__ . '/wyy/cookie';

const WYY_CHART_HOT_ID = 3778678;
const WYY_CHART_SOARING_ID = 19723756;

const QQ_CHART_HOT_TOPID = 26;
const QQ_CHART_SOARING_TOPID = 62;

function respond_json(int $code, string $msg, $data = null): void {
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
    }
    $out = [
        'code' => $code,
        'msg' => $msg,
        'data' => $data,
    ];
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function read_cookie_file(string $path): string {
    if (!is_file($path)) {
        throw new RuntimeException('Cookie file not found: ' . $path);
    }
    $raw = trim((string)file_get_contents($path));
    return $raw;
}

function parse_cookie_kv(string $cookie): array {
    $out = [];
    foreach (explode(';', $cookie) as $part) {
        $part = trim($part);
        if ($part === '' || strpos($part, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $part, 2);
        $k = trim($k);
        $v = trim($v);
        if ($k !== '') {
            $out[$k] = $v;
        }
    }
    return $out;
}

// QQ g_tk hash (5381) as seen in common OSS implementations.
function qq_compute_g_tk(string $qqmusic_key): int {
    $n = 5381;
    $len = strlen($qqmusic_key);
    for ($i = 0; $i < $len; $i++) {
        $n += ($n << 5) + ord($qqmusic_key[$i]);
        $n &= 0x7fffffff;
    }
    return $n;
}

function http_request(string $url, string $method = 'GET', array $headers = [], string $cookie = '', ?string $body = null): string {
    $method = strtoupper($method);

    // Prefer cURL if available (handles gzip/deflate and redirects robustly).
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }

        $hdrs = [];
        foreach ($headers as $h) {
            $hdrs[] = $h;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_ENCODING, ''); // enable gzip/deflate decoding
        curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);

        if ($cookie !== '') {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP request failed: ' . $err);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('HTTP status ' . $status . ' for ' . $url);
        }

        return (string)$resp;
    }

    // Fallback: ext-curl not available.
    // Use stream context, avoid compressed responses to keep parsing deterministic.
    $headerLines = $headers;
    $headerLines[] = 'Accept-Encoding: identity';
    if ($cookie !== '') {
        $headerLines[] = 'Cookie: ' . $cookie;
    }
    if ($method === 'POST') {
        $headerLines[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    $opts = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines) . "\r\n",
            'content' => $body ?? '',
            'timeout' => 30,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ];
    $ctx = stream_context_create($opts);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        $parts = parse_url($url);
        $scheme = isset($parts['scheme']) ? strtolower((string)$parts['scheme']) : '';
        $wrappers = function_exists('stream_get_wrappers') ? stream_get_wrappers() : [];
        $allowUrlFopen = (string)ini_get('allow_url_fopen');

        $hint = '';
        if ($allowUrlFopen === '0') {
            $hint = 'allow_url_fopen=0';
        } elseif ($scheme !== '' && !in_array($scheme, $wrappers, true)) {
            $hint = 'missing stream wrapper for scheme: ' . $scheme;
        } else {
            $hint = 'stream request failed';
        }
        throw new RuntimeException('HTTP request failed (no curl): ' . $url . ' (' . $hint . '). Recommended: enable ext-curl.');
    }

    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string)$http_response_header[0], $m)) {
        $status = (int)$m[1];
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('HTTP status ' . $status . ' for ' . $url);
    }

    return (string)$resp;
}

function normalize_artist_names(array $names): string {
    $clean = [];
    foreach ($names as $name) {
        $name = trim((string)$name);
        if ($name !== '') {
            $clean[] = $name;
        }
    }
    return implode(', ', $clean);
}

function wyy_parse_song_list_from_toplist_html(string $html): array {
    if (!preg_match('/<textarea\s+id="song-list-pre-data"[^>]*>([\s\S]*?)<\/textarea>/', $html, $m)) {
        throw new RuntimeException('WYY parse failed: textarea#song-list-pre-data not found');
    }

    $json = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $arr = json_decode($json, true);
    if (!is_array($arr)) {
        throw new RuntimeException('WYY parse failed: invalid JSON in textarea');
    }

    $out = [];
    foreach ($arr as $item) {
        if (!is_array($item)) {
            continue;
        }

        $title = isset($item['name']) ? (string)$item['name'] : '';
        $id = isset($item['id']) ? (string)$item['id'] : '';
        $album = isset($item['album']) && is_array($item['album']) ? $item['album'] : [];
        $cover = isset($album['picUrl']) ? (string)$album['picUrl'] : '';

        $artists = [];
        if (isset($item['artists']) && is_array($item['artists'])) {
            foreach ($item['artists'] as $a) {
                if (is_array($a) && isset($a['name'])) {
                    $artists[] = (string)$a['name'];
                }
            }
        }
        $artist = normalize_artist_names($artists);

        $share = $id !== '' ? ('https://music.163.com/song?id=' . rawurlencode($id)) : '';

        if ($title === '' || $artist === '' || $share === '') {
            continue;
        }

        $out[] = [
            'title' => $title,
            'artist' => $artist,
            'original_share_url' => $share,
            'original_cover_url' => $cover,
        ];
    }
    return $out;
}

function wyy_fetch_chart(int $chartId, string $cookie): array {
    $url = 'https://music.163.com/discover/toplist?id=' . $chartId;
    $html = http_request($url, 'GET', [
        'User-Agent: Mozilla/5.0',
        'Referer: https://music.163.com/',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    ], $cookie);
    return wyy_parse_song_list_from_toplist_html($html);
}

function wyy_parse_chart_from_sample_file(string $path): array {
    if (!is_file($path)) {
        throw new RuntimeException('WYY sample not found: ' . $path);
    }
    $html = (string)file_get_contents($path);
    return wyy_parse_song_list_from_toplist_html($html);
}

function qq_album_cover_url(string $albumMid): string {
    $albumMid = trim($albumMid);
    if ($albumMid === '') {
        return '';
    }
    return 'https://y.gtimg.cn/music/photo_new/T002R500x500M000' . rawurlencode($albumMid) . '.jpg';
}

function qq_share_url(string $songMid): string {
    $songMid = trim($songMid);
    if ($songMid === '') {
        return '';
    }
    return 'https://y.qq.com/n/ryqq/songDetail/' . rawurlencode($songMid);
}

function qq_musicu_fcg(string $cookie, array $payload): array {
    $cookieKv = parse_cookie_kv($cookie);
    $qqmusicKey = isset($cookieKv['qqmusic_key']) ? (string)$cookieKv['qqmusic_key'] : '';
    $uinRaw = isset($cookieKv['uin']) ? (string)$cookieKv['uin'] : '0';
    $uin = (int)preg_replace('/\D+/', '', $uinRaw);
    $g_tk = $qqmusicKey !== '' ? qq_compute_g_tk($qqmusicKey) : 5381;

    $payload['comm'] = $payload['comm'] ?? [
        'uin' => $uin,
        'format' => 'json',
        'ct' => 24,
        'cv' => 0,
    ];

    $query = [
        'format' => 'json',
        'inCharset' => 'utf8',
        'outCharset' => 'utf-8',
        'platform' => 'yqq.json',
        'needNewCode' => '0',
        'g_tk' => (string)$g_tk,
        'g_tk_new_20200303' => (string)$g_tk,
        'data' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    $url = 'https://u.y.qq.com/cgi-bin/musicu.fcg?' . http_build_query($query);

    $resp = http_request($url, 'GET', [
        'User-Agent: Mozilla/5.0',
        'Referer: https://y.qq.com/',
        'Accept: application/json,text/plain,*/*',
    ], $cookie);

    $json = json_decode($resp, true);
    if (!is_array($json)) {
        throw new RuntimeException('QQ parse failed: invalid JSON from musicu.fcg');
    }
    return $json;
}

function qq_get_all_toplists(string $cookie): array {
    $payload = [
        'topList' => [
            'module' => 'musicToplist.ToplistInfoServer',
            'method' => 'GetAll',
            'param' => new stdClass(),
        ],
    ];
    $json = qq_musicu_fcg($cookie, $payload);
    if (!isset($json['topList']['data']['group']) || !is_array($json['topList']['data']['group'])) {
        throw new RuntimeException('QQ parse failed: topList.data.group missing');
    }
    return $json['topList']['data']['group'];
}

function qq_find_period_for_topid(array $groups, int $topId): string {
    foreach ($groups as $g) {
        if (!is_array($g) || !isset($g['toplist']) || !is_array($g['toplist'])) {
            continue;
        }
        foreach ($g['toplist'] as $t) {
            if (!is_array($t)) {
                continue;
            }
            $id = isset($t['topId']) ? (int)$t['topId'] : (isset($t['id']) ? (int)$t['id'] : -1);
            if ($id !== $topId) {
                continue;
            }
            return isset($t['period']) ? (string)$t['period'] : '';
        }
    }
    return '';
}

function qq_fetch_toplist_detail(string $cookie, int $topId, string $period, int $num = 100): array {
    $payload = [
        'toplist' => [
            'module' => 'musicToplist.ToplistInfoServer',
            'method' => 'GetDetail',
            'param' => [
                'topid' => $topId,
                'num' => $num,
                'period' => $period,
            ],
        ],
    ];
    return qq_musicu_fcg($cookie, $payload);
}

function qq_extract_songs_from_detail_response(array $json): array {
    $songInfoList = null;

    if (isset($json['toplist']['data']['songInfoList']) && is_array($json['toplist']['data']['songInfoList'])) {
        $songInfoList = $json['toplist']['data']['songInfoList'];
    } elseif (isset($json['detail']['data']['songInfoList']) && is_array($json['detail']['data']['songInfoList'])) {
        $songInfoList = $json['detail']['data']['songInfoList'];
    } elseif (isset($json['detail']['data']['data']['songInfoList']) && is_array($json['detail']['data']['data']['songInfoList'])) {
        $songInfoList = $json['detail']['data']['data']['songInfoList'];
    }

    if (!is_array($songInfoList)) {
        throw new RuntimeException('QQ parse failed: songInfoList not found in detail response');
    }

    $out = [];
    foreach ($songInfoList as $song) {
        if (!is_array($song)) {
            continue;
        }
        $title = isset($song['name']) ? (string)$song['name'] : '';
        $mid = isset($song['mid']) ? (string)$song['mid'] : '';

        $artists = [];
        if (isset($song['singer']) && is_array($song['singer'])) {
            foreach ($song['singer'] as $s) {
                if (is_array($s) && isset($s['name'])) {
                    $artists[] = (string)$s['name'];
                }
            }
        }
        $artist = normalize_artist_names($artists);

        $albumMid = '';
        if (isset($song['album']) && is_array($song['album']) && isset($song['album']['mid'])) {
            $albumMid = (string)$song['album']['mid'];
        }

        $share = qq_share_url($mid);
        if ($title === '' || $artist === '' || $share === '') {
            continue;
        }

        $out[] = [
            'title' => $title,
            'artist' => $artist,
            'original_share_url' => $share,
            'original_cover_url' => qq_album_cover_url($albumMid),
        ];
    }
    return $out;
}

function get_param(string $key, string $default = ''): string {
    if (PHP_SAPI === 'cli') {
        global $argv;
        foreach ($argv as $arg) {
            if (strpos($arg, $key . '=') === 0) {
                return substr($arg, strlen($key) + 1);
            }
        }
        return $default;
    }
    return isset($_GET[$key]) ? (string)$_GET[$key] : $default;
}

function main(): void {
    $mode = strtolower(get_param('mode', 'live')); // live | sample

    try {
        $qqCookie = read_cookie_file(QQ_COOKIE_PATH);
        $wyyCookie = read_cookie_file(WYY_COOKIE_PATH);

        $charts = [
            'wyy' => [
                'hot' => [],
                'soaring' => [],
            ],
            'qq' => [
                'hot' => [],
                'soaring' => [],
            ],
        ];

        if ($mode === 'sample') {
            $charts['wyy']['hot'] = wyy_parse_chart_from_sample_file(__DIR__ . '/wyy/热歌榜抓包数据.txt');
            $charts['wyy']['soaring'] = wyy_parse_chart_from_sample_file(__DIR__ . '/wyy/飙升榜抓包数据.txt');
            // QQ sample captures do not include a clean musicu.fcg GetDetail JSON body in the provided txt exports.
            $charts['qq']['hot'] = [];
            $charts['qq']['soaring'] = [];
        } else {
            $charts['wyy']['hot'] = wyy_fetch_chart(WYY_CHART_HOT_ID, $wyyCookie);
            $charts['wyy']['soaring'] = wyy_fetch_chart(WYY_CHART_SOARING_ID, $wyyCookie);

            $groups = qq_get_all_toplists($qqCookie);
            $periodHot = qq_find_period_for_topid($groups, QQ_CHART_HOT_TOPID);
            $periodSoaring = qq_find_period_for_topid($groups, QQ_CHART_SOARING_TOPID);

            $hotJson = qq_fetch_toplist_detail($qqCookie, QQ_CHART_HOT_TOPID, $periodHot);
            $soaringJson = qq_fetch_toplist_detail($qqCookie, QQ_CHART_SOARING_TOPID, $periodSoaring);
            $charts['qq']['hot'] = qq_extract_songs_from_detail_response($hotJson);
            $charts['qq']['soaring'] = qq_extract_songs_from_detail_response($soaringJson);
        }

        respond_json(200, 'ok', [
            'generated_at' => gmdate('c'),
            'mode' => $mode,
            'charts' => $charts,
        ]);
    } catch (Throwable $e) {
        respond_json(500, $e->getMessage(), [
            'mode' => $mode,
        ]);
    }
}

main();
