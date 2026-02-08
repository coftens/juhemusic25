<?php
declare(strict_types=1);

require __DIR__ . '/php_api_common.php';

// GET /lyrics.php?url={share_url}
// Returns cached lyrics from MySQL; on miss, fetches from QQ/WYY and stores.

const QQ_COOKIE_PATH = __DIR__ . '/qq/cookie';
const WYY_COOKIE_PATH = __DIR__ . '/wyy/cookie';

function read_cookie_file(string $path): string {
    if (!is_file($path)) {
        return '';
    }
    return trim((string)file_get_contents($path));
}

function detect_source(string $url): string {
    $u = strtolower($url);
    if (strpos($u, 'y.qq.com') !== false) return 'qq';
    if (strpos($u, 'music.163.com') !== false) return 'wyy';
    if (strpos($u, 'qishui.douyin.com') !== false || strpos($u, 'douyin.com') !== false) return 'qishui';
    throw new RuntimeException('unsupported url');
}

function extract_qishui_id(string $url): string {
    if (preg_match('~track_id=(\d+)~', $url, $m)) {
        return $m[1];
    }
    // Handle short links by calling Python or just using the URL as key
    return $url; 
}

function fetch_qishui_lyrics(string $idOrUrl): array {
    // We need to get lyrics from port 8372
    // If it's a URL, we might need to resolve it, but server.py handles that.
    // For simplicity, we call the qishui_api track endpoint.
    $id = $idOrUrl;
    if (strpos($idOrUrl, 'http') !== false) {
        // If it's a URL, use server.py to parse it first or extract ID
        // But for lyrics, usually we have the track_id in the app.
        // Let's assume the app sends the track_id as url if it's qishui.
    }
    
    $apiUrl = "http://127.0.0.1:8372/track?id=" . urlencode($idOrUrl);
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($code !== 200) {
        throw new RuntimeException("Qishui API lyric fetch failed: $code");
    }
    
    $json = json_decode((string)$resp, true);
    $lrc = (string)($json['lyrics'] ?? '');
    
    return [
        'lyric_lrc' => $lrc,
        'trans_lrc' => '',
        'roma_lrc' => '',
        'raw_json' => (string)$resp
    ];
}

function extract_qq_songmid(string $url): string {
    if (preg_match('~/songDetail/([^/?#]+)~', $url, $m)) {
        return $m[1];
    }
    if (preg_match('~\bmid=([A-Za-z0-9]+)~', $url, $m)) {
        return $m[1];
    }
    throw new RuntimeException('cannot extract qq songmid');
}

function extract_wyy_song_id(string $url): string {
    if (preg_match('~[?#&]id=(\d+)~', $url, $m)) {
        return $m[1];
    }
    if (preg_match('~\b(\d{6,})\b~', $url, $m)) {
        return $m[1];
    }
    throw new RuntimeException('cannot extract wyy song id');
}

function is_base64_string(string $s): bool {
    $s = trim($s);
    if ($s === '' || (strlen($s) % 4) !== 0) return false;
    return preg_match('/^[A-Za-z0-9+\/=]+$/', $s) === 1;
}

function http_get_text_with_cookie(string $url, string $cookie = ''): string {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('ext-curl is required');
    }
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('curl_init failed');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0',
        'Referer: https://y.qq.com/',
        'Accept: */*',
    ]);
    if ($cookie !== '') curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP failed: ' . $err);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('HTTP status ' . $status);
    }
    return (string)$resp;
}

function strip_jsonp(string $text): string {
    $t = trim($text);
    if ($t === '') return $t;
    if ($t[0] === '{' || $t[0] === '[') return $t;
    $l = strpos($t, '(');
    $r = strrpos($t, ')');
    if ($l !== false && $r !== false && $r > $l) {
        return trim(substr($t, $l + 1, $r - $l - 1));
    }
    return $t;
}

function fetch_qq_lyrics(string $songmid, string $cookie): array {
    $pcachetime = (string)round(microtime(true) * 1000);
    $query = [
        'callback' => 'MusicJsonCallback_lrc',
        'pcachetime' => $pcachetime,
        'songmid' => $songmid,
        'g_tk' => '5381',
        'jsonpCallback' => 'MusicJsonCallback_lrc',
        'loginUin' => '0',
        'hostUin' => '0',
        'format' => 'jsonp',
        'inCharset' => 'utf8',
        'outCharset' => 'utf8',
        'notice' => '0',
        'platform' => 'yqq',
        'needNewCode' => '0',
    ];
    $url = 'https://c.y.qq.com/lyric/fcgi-bin/fcg_query_lyric_new.fcg?' . http_build_query($query);
    $raw = http_get_text_with_cookie($url, $cookie);
    $jsonText = strip_jsonp($raw);
    $json = json_decode($jsonText, true);
    if (!is_array($json)) {
        throw new RuntimeException('invalid lyric response');
    }
    if (isset($json['code']) && (int)$json['code'] !== 0) {
        throw new RuntimeException('qq lyric code=' . (string)$json['code']);
    }
    $lyric = isset($json['lyric']) ? (string)$json['lyric'] : '';
    $trans = isset($json['trans']) ? (string)$json['trans'] : '';
    $roma = isset($json['roma']) ? (string)$json['roma'] : '';

    if ($lyric !== '' && is_base64_string($lyric)) {
        $decoded = base64_decode($lyric, true);
        if ($decoded !== false) $lyric = $decoded;
    }
    if ($trans !== '' && is_base64_string($trans)) {
        $decoded = base64_decode($trans, true);
        if ($decoded !== false) $trans = $decoded;
    }
    if ($roma !== '' && is_base64_string($roma)) {
        $decoded = base64_decode($roma, true);
        if ($decoded !== false) $roma = $decoded;
    }

    return [
        'lyric_lrc' => $lyric,
        'trans_lrc' => $trans,
        'roma_lrc' => $roma,
        'raw_json' => json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

// ---- NetEase weapi crypto (ported from 163MusicLyrics C#) ----

const NETEASE_MODULUS = '00e0b509f6259df8642dbc35662901477df22677ec152b5ff68ace615bb7b725152b3ab17a876aea8a5aa76d2e417629ec4ee341f56135fccf695280104e0312ecbda92557c93870114af6c9d05c4f7f0c3685b7a46bee255932575cce10b424d813cfe4875d3e82047b97ddef52741d546b8e289dc6935b3ece0462db0a22b8e7';
const NETEASE_NONCE = '0CoJUm6Qyw8W8jud';
const NETEASE_PUBKEY = '010001';
const NETEASE_VI = '0102030405060708';

function bc_hex_to_dec(string $hex): string {
    $hex = strtolower(ltrim($hex, '0'));
    if ($hex === '') return '0';
    $dec = '0';
    for ($i = 0; $i < strlen($hex); $i++) {
        $c = $hex[$i];
        $n = strpos('0123456789abcdef', $c);
        if ($n === false) throw new RuntimeException('invalid hex');
        $dec = bcadd(bcmul($dec, '16'), (string)$n);
    }
    return $dec;
}

function bc_dec_to_hex(string $dec): string {
    $dec = ltrim($dec);
    if ($dec === '' || $dec === '0') return '0';
    $hex = '';
    while (bccomp($dec, '0') > 0) {
        $rem = (int)bcmod($dec, '16');
        $hex = '0123456789abcdef'[$rem] . $hex;
        $dec = bcdiv($dec, '16', 0);
    }
    return $hex;
}

function netease_rsa_encode(string $text): string {
    $srtext = strrev($text);
    $hex = bin2hex($srtext);
    $a = bc_hex_to_dec($hex);
    $b = bc_hex_to_dec(NETEASE_PUBKEY);
    $c = bc_hex_to_dec(NETEASE_MODULUS);
    $pow = bcpowmod($a, $b, $c);
    $key = bc_dec_to_hex($pow);
    $key = str_pad($key, 256, '0', STR_PAD_LEFT);
    return strlen($key) > 256 ? substr($key, -256) : $key;
}

function netease_aes_encode(string $plain, string $secret): string {
    $iv = NETEASE_VI;
    $cipher = 'AES-128-CBC';
    $enc = openssl_encrypt($plain, $cipher, $secret, OPENSSL_RAW_DATA, $iv);
    if ($enc === false) {
        throw new RuntimeException('openssl_encrypt failed');
    }
    return base64_encode($enc);
}

function netease_create_secret_key(int $length = 16): string {
    $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

function netease_prepare(string $rawJson): array {
    $secretKey = netease_create_secret_key(16);
    $encSecKey = netease_rsa_encode($secretKey);
    $params = netease_aes_encode($rawJson, NETEASE_NONCE);
    $params = netease_aes_encode($params, $secretKey);
    return ['params' => $params, 'encSecKey' => $encSecKey];
}

function http_post_form(string $url, array $form, string $cookie = '', string $referer = ''): string {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('ext-curl is required');
    }
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('curl_init failed');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    $hdrs = [
        'User-Agent: Mozilla/5.0',
        'Accept: application/json,text/plain,*/*',
        'Content-Type: application/x-www-form-urlencoded',
    ];
    if ($referer !== '') $hdrs[] = 'Referer: ' . $referer;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
    if ($cookie !== '') curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP failed: ' . $err);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('HTTP status ' . $status);
    }
    return (string)$resp;
}

function fetch_wyy_lyrics(string $songId, string $cookie): array {
    $apiUrl = 'https://music.163.com/weapi/song/lyric?csrf_token=';
    $payload = [
        'id' => $songId,
        'os' => 'pc',
        'lv' => '-1',
        'kv' => '-1',
        'tv' => '-1',
        'rv' => '-1',
        'yv' => '-1',
        'ytv' => '-1',
        'yrv' => '-1',
        'csrf_token' => '',
    ];
    $prepared = netease_prepare(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $raw = http_post_form($apiUrl, $prepared, $cookie, 'https://music.163.com/');
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('invalid netease response');
    }
    if (isset($json['code']) && (int)$json['code'] !== 200) {
        throw new RuntimeException('wyy lyric code=' . (string)$json['code']);
    }
    $lrc = isset($json['lrc']['lyric']) ? (string)$json['lrc']['lyric'] : '';
    $tlyric = isset($json['tlyric']['lyric']) ? (string)$json['tlyric']['lyric'] : '';
    $roma = isset($json['romalrc']['lyric']) ? (string)$json['romalrc']['lyric'] : '';

    return [
        'lyric_lrc' => $lrc,
        'trans_lrc' => $tlyric,
        'roma_lrc' => $roma,
        'raw_json' => json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

function mysql_get_lyrics(PDO $pdo, string $source, string $songKey): ?array {
    $stmt = $pdo->prepare('SELECT lyric_lrc, trans_lrc, roma_lrc, original_share_url, updated_at FROM music_lyrics WHERE source=? AND song_key=? LIMIT 1');
    $stmt->execute([$source, $songKey]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function mysql_upsert_lyrics(PDO $pdo, string $source, string $songKey, string $shareUrl, array $payload): void {
    $sql = 'INSERT INTO music_lyrics (source, song_key, original_share_url, lyric_lrc, trans_lrc, roma_lrc, raw_json) VALUES (?,?,?,?,?,?,?) '
        . 'ON DUPLICATE KEY UPDATE original_share_url=VALUES(original_share_url), lyric_lrc=VALUES(lyric_lrc), trans_lrc=VALUES(trans_lrc), roma_lrc=VALUES(roma_lrc), raw_json=VALUES(raw_json)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $source,
        $songKey,
        $shareUrl,
        (string)($payload['lyric_lrc'] ?? ''),
        (string)($payload['trans_lrc'] ?? ''),
        (string)($payload['roma_lrc'] ?? ''),
        (string)($payload['raw_json'] ?? ''),
    ]);
}

try {
    $url = trim(api_param('url', ''));
    $dryRun = api_param('dry_run', env_get('DRY_RUN', '0')) === '1';
    if ($url === '') {
        api_json(400, 'missing url');
        exit;
    }

    $source = detect_source($url);
    if ($source === 'qq') {
        $songKey = extract_qq_songmid($url);
    } elseif ($source === 'wyy') {
        $songKey = extract_wyy_song_id($url);
    } else {
        $songKey = extract_qishui_id($url);
    }

    $useDb = env_get('MYSQL_DSN', '') !== '' && env_get('MYSQL_USER', '') !== '';
    $pdo = null;
    if ($useDb) {
        $pdo = pdo_connect_from_env();
        mysql_migrate_lyrics($pdo);
        $cached = mysql_get_lyrics($pdo, $source, $songKey);
        if (is_array($cached) && ((string)($cached['lyric_lrc'] ?? '') !== '')) {
            api_json(200, 'ok', [
                'source' => $source,
                'song_key' => $songKey,
                'original_share_url' => $url,
                'lyric_lrc' => (string)$cached['lyric_lrc'],
                'trans_lrc' => (string)($cached['trans_lrc'] ?? ''),
                'roma_lrc' => (string)($cached['roma_lrc'] ?? ''),
                'cached' => true,
            ]);
            exit;
        }
    } elseif (!$dryRun) {
        throw new RuntimeException('MySQL config missing. Provide MYSQL_DSN and MYSQL_USER (or use dry_run=1).');
    }

    if ($source === 'qq') {
        $cookie = read_cookie_file(env_get('QQ_COOKIE_PATH', QQ_COOKIE_PATH));
        $payload = fetch_qq_lyrics($songKey, $cookie);
    } elseif ($source === 'wyy') {
        $cookie = read_cookie_file(env_get('WYY_COOKIE_PATH', WYY_COOKIE_PATH));
        $payload = fetch_wyy_lyrics($songKey, $cookie);
    } else {
        $payload = fetch_qishui_lyrics($url);
    }
    if ($pdo instanceof PDO) {
        mysql_upsert_lyrics($pdo, $source, $songKey, $url, $payload);
    }

    api_json(200, 'ok', [
        'source' => $source,
        'song_key' => $songKey,
        'original_share_url' => $url,
        'lyric_lrc' => (string)($payload['lyric_lrc'] ?? ''),
        'trans_lrc' => (string)($payload['trans_lrc'] ?? ''),
        'roma_lrc' => (string)($payload['roma_lrc'] ?? ''),
        'cached' => false,
        'dry_run' => $dryRun,
    ]);
} catch (Throwable $e) {
    api_json(500, $e->getMessage());
}
