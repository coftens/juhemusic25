<?php
declare(strict_types=1);

// Common helpers for Step 5 PHP APIs.

const DEFAULT_PY_BASE_URL = 'http://172.21.28.219:8002';

// Optional local config file (written by /admin installer).
// Return format: <?php return ['MYSQL_DSN' => '...', ...];
const LOCAL_CONFIG_FILE = __DIR__ . '/config.local.php';

$APP_CONFIG = [];
if (is_file(LOCAL_CONFIG_FILE)) {
    $cfg = @include LOCAL_CONFIG_FILE;
    if (is_array($cfg)) {
        $APP_CONFIG = $cfg;
    }
}

function api_param(string $key, string $default = ''): string
{
    if (PHP_SAPI === 'cli') {
        global $argv;
        foreach ($argv as $arg) {
            if (strpos($arg, $key . '=') === 0) {
                return substr($arg, strlen($key) + 1);
            }
        }
        return $default;
    }
    return isset($_GET[$key]) ? (string) $_GET[$key] : $default;
}

function env_get(string $key, string $default = ''): string
{
    $v = getenv($key);
    if ($v !== false) {
        return (string) $v;
    }

    global $APP_CONFIG;
    if (is_array($APP_CONFIG) && array_key_exists($key, $APP_CONFIG)) {
        $vv = $APP_CONFIG[$key];
        if (is_bool($vv)) {
            return $vv ? '1' : '0';
        }
        if (is_scalar($vv)) {
            return (string) $vv;
        }
    }
    return $default;
}

function api_json(int $code, string $msg, $data = null): void
{
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'code' => $code,
        'msg' => $msg,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function http_get_json(string $url, array $query = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('ext-curl is required');
    }
    $u = $url;
    if ($query) {
        $sep = (strpos($u, '?') === false) ? '?' : '&';
        $u .= $sep . http_build_query($query);
    }

    $ch = curl_init($u);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'User-Agent: Mozilla/5.0',
    ]);

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
    $json = json_decode((string) $resp, true);
    if (!is_array($json)) {
        throw new RuntimeException('Invalid JSON');
    }
    return $json;
}

function pdo_connect_from_env(): PDO
{
    $dsn = env_get('MYSQL_DSN', '');
    $user = env_get('MYSQL_USER', '');
    $pass = env_get('MYSQL_PASS', '');
    if ($dsn === '' || $user === '') {
        throw new RuntimeException('MySQL config missing: MYSQL_DSN and MYSQL_USER');
    }
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ]);
    return $pdo;
}

function mysql_migrate_song_cache(PDO $pdo): void
{
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `music_song_cache` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source` varchar(10) NOT NULL COMMENT 'enum: qq, wyy, qishui',
  `share_hash` char(32) NOT NULL COMMENT 'md5(source + url) for indexing',
  `original_share_url` varchar(500) NOT NULL,
  `original_cover_url` text,
  `hosted_cover_url` text,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_source_hash` (`source`, `share_hash`),
  KEY `idx_source_share_prefix` (`source`, `original_share_url`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    $pdo->exec($sql);

    // If upgrading from older schema: add share_hash and indexes.
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM music_song_cache LIKE 'share_hash'")->fetchAll();
        if (!$cols) {
            $pdo->exec("ALTER TABLE music_song_cache ADD COLUMN share_hash char(32) NOT NULL DEFAULT '' AFTER source");
        }
    } catch (Throwable $e) {
        // best-effort
    }

    try {
        // Ensure index exists; ignore errors if already present.
        $pdo->exec("ALTER TABLE music_song_cache ADD UNIQUE KEY uniq_source_hash (source, share_hash)");
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec("ALTER TABLE music_song_cache ADD KEY idx_source_share_prefix (source, original_share_url(191))");
    } catch (Throwable $e) {
    }
}

function mysql_migrate_home_items(PDO $pdo): void
{
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `music_home_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source` varchar(10) NOT NULL COMMENT 'enum: qq, wyy',
  `section` varchar(32) NOT NULL COMMENT 'e.g. hotRecommend, newSonglist',
  `item_type` varchar(16) NOT NULL COMMENT 'playlist|song|tag',
  `item_id` varchar(64) NOT NULL,
  `title` varchar(255) NOT NULL,
  `subtitle` varchar(255) DEFAULT '' ,
  `metric` bigint DEFAULT 0 COMMENT 'play count or misc',
  `original_share_url` varchar(500) NOT NULL,
  `original_cover_url` text,
  `hosted_cover_url` text,
  `extra_json` text,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_src_sec_type_id` (`source`, `section`, `item_type`, `item_id`),
  KEY `idx_src_sec` (`source`, `section`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    $pdo->exec($sql);
}

function mysql_migrate_lyrics(PDO $pdo): void
{
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `music_lyrics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source` varchar(10) NOT NULL COMMENT 'enum: qq, wyy',
  `song_key` varchar(64) NOT NULL COMMENT 'qq songmid or wyy song id',
  `original_share_url` varchar(500) NOT NULL,
  `lyric_lrc` mediumtext,
  `trans_lrc` mediumtext,
  `roma_lrc` mediumtext,
  `raw_json` mediumtext,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_source_song` (`source`, `song_key`),
  KEY `idx_share` (`original_share_url`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    $pdo->exec($sql);
}

function mysql_migrate_charts(PDO $pdo): void
{
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `music_charts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source` varchar(10) NOT NULL COMMENT 'enum: qq, wyy',
  `type` varchar(20) NOT NULL COMMENT 'enum: hot, soaring',
  `title` varchar(255) NOT NULL,
  `artist` varchar(255) NOT NULL,
  `original_share_url` varchar(500) NOT NULL,
  `original_cover_url` text,
  `hosted_cover_url` text,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_source_type` (`source`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    $pdo->exec($sql);
}

function mysql_get_hosted_cover(PDO $pdo, string $source, string $shareUrl): string
{
    $hash = md5($source . '|' . $shareUrl);
    $stmt = $pdo->prepare('SELECT hosted_cover_url FROM music_song_cache WHERE source=? AND share_hash=? LIMIT 1');
    $stmt->execute([$source, $hash]);
    $row = $stmt->fetch();
    if (is_array($row) && !empty($row['hosted_cover_url'])) {
        $u = (string) $row['hosted_cover_url'];
        if (!is_oil_url($u)) {
            return $u;
        }
    }

    // fallback: charts cache (hot/soaring)
    $stmt2 = $pdo->prepare('SELECT hosted_cover_url FROM music_charts WHERE source=? AND original_share_url=? AND hosted_cover_url IS NOT NULL ORDER BY updated_at DESC LIMIT 1');
    $stmt2->execute([$source, $shareUrl]);
    $row2 = $stmt2->fetch();
    if (is_array($row2) && !empty($row2['hosted_cover_url'])) {
        $u = (string) $row2['hosted_cover_url'];
        if (!is_oil_url($u)) {
            return $u;
        }
    }
    return '';
}

function is_oil_url(string $url): bool
{
    return $url !== '' && strpos($url, 'oilgasgpts.com') !== false;
}

function normalize_cover_url(string $hosted, string $original): string
{
    // 优先使用 hosted，但必须不是 Oil URL
    if ($hosted !== '' && !is_oil_url($hosted)) {
        return $hosted;
    }
    // 否则使用 original，但也必须不是 Oil URL
    if ($original !== '' && !is_oil_url($original)) {
        return $original;
    }
    // 都不可用，返回空
    return '';
}

function mysql_upsert_cover(PDO $pdo, string $source, string $shareUrl, string $originalCover, string $hostedCover): void
{
    $hash = md5($source . '|' . $shareUrl);
    $sql = 'INSERT INTO music_song_cache (source, share_hash, original_share_url, original_cover_url, hosted_cover_url) VALUES (?,?,?,?,?) '
        . 'ON DUPLICATE KEY UPDATE original_share_url=VALUES(original_share_url), original_cover_url=VALUES(original_cover_url), hosted_cover_url=VALUES(hosted_cover_url)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$source, $hash, $shareUrl, $originalCover, $hostedCover]);
}

function download_to_temp(string $url, string $tmpDir): string
{
    if (!is_dir($tmpDir)) {
        throw new RuntimeException('TMP_DIR not a directory: ' . $tmpDir);
    }

    $ext = 'jpg';
    $path = parse_url($url, PHP_URL_PATH);
    if (is_string($path)) {
        $base = basename($path);
        $dot = strrpos($base, '.');
        if ($dot !== false) {
            $cand = strtolower(substr($base, $dot + 1));
            if (preg_match('/^[a-z0-9]{1,6}$/', $cand)) {
                $ext = $cand;
            }
        }
    }

    $tmpFile = rtrim($tmpDir, '/\\') . DIRECTORY_SEPARATOR . 'cover_' . bin2hex(random_bytes(8)) . '.' . $ext;

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    $fp = fopen($tmpFile, 'wb');
    if ($fp === false) {
        curl_close($ch);
        throw new RuntimeException('Failed to open temp file: ' . $tmpFile);
    }

    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0',
        'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
    ]);

    $ok = curl_exec($ch);
    if ($ok === false) {
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        @unlink($tmpFile);
        throw new RuntimeException('Cover download failed: ' . $err);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    if ($status < 200 || $status >= 300) {
        @unlink($tmpFile);
        throw new RuntimeException('Cover download HTTP status ' . $status);
    }
    if (!is_file($tmpFile) || (int) filesize($tmpFile) <= 0) {
        @unlink($tmpFile);
        throw new RuntimeException('Cover download empty file');
    }
    return $tmpFile;
}

function upload_to_image_host_jike(string $localFilePath): string
{
    $url = 'http://www.jiketianqi.com/weatapi/file/upload';
    $post = ['files' => new CURLFile($localFilePath)];

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0',
        'Referer: http://www.jiketianqi.com/',
        'Origin: http://www.jiketianqi.com',
        'Accept: application/json,text/plain,*/*',
    ]);

    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Jike upload failed: ' . $err);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Jike upload HTTP status ' . $status);
    }

    $json = json_decode((string) $resp, true);
    if (!is_array($json) || !isset($json['data']) || !is_array($json['data']) || !isset($json['data'][0])) {
        throw new RuntimeException('Jike upload response parse failed');
    }
    $u = (string) $json['data'][0];
    if ($u === '') {
        throw new RuntimeException('Jike upload returned empty url');
    }
    return $u;
}

function upload_to_image_host_oil(string $localFilePath): string
{
    $url = 'https://cn.oilgasgpts.com/file/upload';
    $post = ['file' => new CURLFile($localFilePath)];

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0',
        'Referer: https://cn.oilgasgpts.com/feedback',
        'Origin: https://cn.oilgasgpts.com',
        'Accept: application/json,text/plain,*/*',
    ]);

    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Oil upload failed: ' . $err);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Oil upload HTTP status ' . $status);
    }

    $json = json_decode((string) $resp, true);
    if (!is_array($json) || !isset($json['msg'])) {
        throw new RuntimeException('Oil upload response parse failed');
    }
    $u = (string) $json['msg'];
    if ($u === '') {
        throw new RuntimeException('Oil upload returned empty url');
    }
    return $u;
}

function upload_to_image_host(string $localFilePath, string &$rrState): string
{
    $rrState = 'jike';
    return upload_to_image_host_jike($localFilePath);
}

function maybe_async_cover_upload(PDO $pdo, string $source, string $shareUrl, string $originalCoverUrl): void
{
    if ($originalCoverUrl === '' || $shareUrl === '') {
        return;
    }
    $enabled = env_get('SEARCH_ASYNC_UPLOAD', '0') === '1';
    if (!$enabled) {
        return;
    }
    $existing = mysql_get_hosted_cover($pdo, $source, $shareUrl);
    if ($existing !== '') {
        return;
    }

    // Best-effort async: flush response first under FPM.
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }

    $tmpDir = env_get('TMP_DIR', sys_get_temp_dir());
    $rr = env_get('UPLOAD_RR', 'jike');

    try {
        $tmpFile = download_to_temp($originalCoverUrl, $tmpDir);
        try {
            $hosted = upload_to_image_host($tmpFile, $rr);
        } finally {
            @unlink($tmpFile);
        }
        mysql_upsert_cover($pdo, $source, $shareUrl, $originalCoverUrl, $hosted);
    } catch (Throwable $e) {
        // best-effort only
    }
}

function api_require_method(string $method): void
{
    if (PHP_SAPI === 'cli')
        return;
    $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($m !== strtoupper($method)) {
        api_json(405, 'method not allowed');
        exit;
    }
}

function api_read_json_body(): array
{
    if (PHP_SAPI === 'cli') {
        // CLI: allow passing json=... for quick tests.
        $raw = api_param('json', '');
        $j = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($j) ? $j : [];
    }
    $raw = (string) file_get_contents('php://input');
    if (trim($raw) === '')
        return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function auth_base64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function auth_new_token(int $bytes = 32): string
{
    if ($bytes < 16)
        $bytes = 16;
    if ($bytes > 64)
        $bytes = 64;
    return auth_base64url(random_bytes($bytes));
}

function auth_hash_token(string $token): string
{
    return hash('sha256', $token);
}

function auth_get_bearer_token(): string
{
    if (PHP_SAPI === 'cli') {
        return api_param('token', '');
    }
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!is_string($hdr) || $hdr === '')
        return '';
    if (stripos($hdr, 'Bearer ') !== 0)
        return '';
    return trim(substr($hdr, 7));
}

function mysql_migrate_users(PDO $pdo): void
{
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `music_users` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `avatar_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    $pdo->exec($sql);

    // Best-effort cleanup for legacy schemas.
    try {
        $pdo->exec('ALTER TABLE music_users DROP INDEX uniq_email');
    } catch (Throwable $e) {
        // ignore
    }
}

function mysql_migrate_user_access_tokens(PDO $pdo): void
{
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `music_user_access_tokens` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `token_hash` char(64) NOT NULL,
  `device_id` varchar(64) NOT NULL DEFAULT '',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token_hash` (`token_hash`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    $pdo->exec($sql);
}

function mysql_migrate_user_refresh_tokens(PDO $pdo): void
{
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `music_user_refresh_tokens` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `token_hash` char(64) NOT NULL,
  `device_id` varchar(64) NOT NULL DEFAULT '',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `rotated_from_id` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token_hash` (`token_hash`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    $pdo->exec($sql);
}

function mysql_migrate_user_favorites(PDO $pdo): void
{
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `music_user_favorites` (
  `user_id` bigint NOT NULL,
  `share_hash` char(32) NOT NULL,
  `platform` varchar(10) NOT NULL,
  `share_url` varchar(500) NOT NULL,
  `name` varchar(255) NOT NULL,
  `artist` varchar(255) NOT NULL,
  `cover_url` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `share_hash`),
  KEY `idx_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    $pdo->exec($sql);
}

function mysql_migrate_user_recents(PDO $pdo): void
{
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `music_user_recents` (
  `user_id` bigint NOT NULL,
  `share_hash` char(32) NOT NULL,
  `platform` varchar(10) NOT NULL,
  `share_url` varchar(500) NOT NULL,
  `name` varchar(255) NOT NULL,
  `artist` varchar(255) NOT NULL,
  `cover_url` text,
  `last_played_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `share_hash`),
  KEY `idx_user_last_played` (`user_id`, `last_played_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    $pdo->exec($sql);
}

function mysql_migrate_user_playlists(PDO $pdo): void
{
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `music_user_playlists` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `platform` varchar(10) NOT NULL DEFAULT 'local',
  `external_id` varchar(64) DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `cover_url` text,
  `track_count` int NOT NULL DEFAULT 0,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  UNIQUE KEY `uniq_user_external` (`user_id`, `platform`, `external_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    $pdo->exec($sql);

    // Migration: add columns if missing
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM music_user_playlists LIKE 'platform'")->fetchAll();
        if (!$cols) {
            $pdo->exec("ALTER TABLE music_user_playlists ADD COLUMN platform varchar(10) NOT NULL DEFAULT 'local' AFTER user_id");
            $pdo->exec("ALTER TABLE music_user_playlists ADD COLUMN external_id varchar(64) DEFAULT NULL AFTER platform");
            $pdo->exec("ALTER TABLE music_user_playlists ADD COLUMN cover_url text AFTER name");
            $pdo->exec("ALTER TABLE music_user_playlists ADD COLUMN track_count int NOT NULL DEFAULT 0 AFTER cover_url");
            // Add unique index for external playlists (ignore duplicate errors if any data conflict)
            try {
                $pdo->exec("ALTER TABLE music_user_playlists ADD UNIQUE KEY uniq_user_external (user_id, platform, external_id)");
            } catch (Throwable $e2) {
            }
        }

        // Migration: add track_count if missing (for existing installations)
        $cols2 = $pdo->query("SHOW COLUMNS FROM music_user_playlists LIKE 'track_count'")->fetchAll();
        if (!$cols2) {
            $pdo->exec("ALTER TABLE music_user_playlists ADD COLUMN track_count int NOT NULL DEFAULT 0 AFTER cover_url");
        }
    } catch (Throwable $e) {
    }
}

function mysql_migrate_user_playlist_tracks(PDO $pdo): void
{
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `music_user_playlist_tracks` (
  `playlist_id` bigint NOT NULL,
  `share_hash` char(32) NOT NULL,
  `platform` varchar(10) NOT NULL,
  `share_url` varchar(500) NOT NULL,
  `name` varchar(255) NOT NULL,
  `artist` varchar(255) NOT NULL,
  `cover_url` text,
  `position` int DEFAULT 0,
  `added_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`playlist_id`, `share_hash`),
  KEY `idx_playlist_pos` (`playlist_id`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    $pdo->exec($sql);
}

function mysql_migrate_listening_daily(PDO $pdo): void
{
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `music_listening_daily` (
  `user_id` bigint NOT NULL,
  `day` date NOT NULL,
  `seconds` int NOT NULL DEFAULT 0,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    $pdo->exec($sql);
}

function mysql_auth_find_user_by_access_token(PDO $pdo, string $token): ?array
{
    $token = trim($token);
    if ($token === '')
        return null;
    $hash = auth_hash_token($token);
    $stmt = $pdo->prepare('SELECT t.user_id, u.username, u.avatar_path FROM music_user_access_tokens t JOIN music_users u ON u.id=t.user_id WHERE t.token_hash=? AND (t.expires_at IS NULL OR t.expires_at > NOW()) LIMIT 1');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function mysql_auth_mint_tokens(PDO $pdo, int $userId, string $deviceId): array
{
    $deviceId = trim($deviceId);
    if ($deviceId === '')
        $deviceId = 'unknown';

    $access = auth_new_token(32);
    $refresh = auth_new_token(48);
    $accessHash = auth_hash_token($access);
    $refreshHash = auth_hash_token($refresh);

    $accessTtlMin = (int) env_get('AUTH_ACCESS_TTL_MIN', '15');
    if ($accessTtlMin < 5)
        $accessTtlMin = 5;
    if ($accessTtlMin > 120)
        $accessTtlMin = 120;

    $refreshTtlDays = (int) env_get('AUTH_REFRESH_TTL_DAYS', '30');
    if ($refreshTtlDays < 7)
        $refreshTtlDays = 7;
    if ($refreshTtlDays > 180)
        $refreshTtlDays = 180;

    $stmt = $pdo->prepare('INSERT INTO music_user_access_tokens (user_id, token_hash, device_id, expires_at) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL ? MINUTE))');
    $stmt->execute([$userId, $accessHash, $deviceId, $accessTtlMin]);

    $stmt2 = $pdo->prepare('INSERT INTO music_user_refresh_tokens (user_id, token_hash, device_id, expires_at) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL ? DAY))');
    $stmt2->execute([$userId, $refreshHash, $deviceId, $refreshTtlDays]);

    return [
        'access_token' => $access,
        'refresh_token' => $refresh,
        'access_expires_in' => $accessTtlMin * 60,
        'refresh_expires_in' => $refreshTtlDays * 86400,
    ];
}

function mysql_auth_refresh_rotate(PDO $pdo, string $refreshToken, string $deviceId): ?array
{
    $refreshToken = trim($refreshToken);
    if ($refreshToken === '')
        return null;
    $hash = auth_hash_token($refreshToken);
    $deviceId = trim($deviceId);
    if ($deviceId === '')
        $deviceId = 'unknown';

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, user_id, device_id, expires_at, revoked_at FROM music_user_refresh_tokens WHERE token_hash=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            $pdo->rollBack();
            return null;
        }
        if (!empty($row['revoked_at'])) {
            $pdo->rollBack();
            return null;
        }
        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            $pdo->rollBack();
            return null;
        }
        if ((string) ($row['device_id'] ?? '') !== $deviceId) {
            $pdo->rollBack();
            return null;
        }

        $oldId = (int) $row['id'];
        $userId = (int) $row['user_id'];

        // revoke old
        $stmt2 = $pdo->prepare('UPDATE music_user_refresh_tokens SET revoked_at=NOW() WHERE id=?');
        $stmt2->execute([$oldId]);

        $tokens = mysql_auth_mint_tokens($pdo, $userId, $deviceId);
        // link rotation (best-effort)
        $newHash = auth_hash_token((string) $tokens['refresh_token']);
        $stmt3 = $pdo->prepare('UPDATE music_user_refresh_tokens SET rotated_from_id=? WHERE token_hash=?');
        $stmt3->execute([$oldId, $newHash]);

        $pdo->commit();
        $tokens['user_id'] = $userId;
        return $tokens;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function api_require_user(PDO $pdo): array
{
    $t = auth_get_bearer_token();
    $u = mysql_auth_find_user_by_access_token($pdo, $t);
    if ($u === null) {
        api_json(401, 'unauthorized');
        exit;
    }
    return $u;
}
