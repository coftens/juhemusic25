<?php
declare(strict_types=1);

/*
Step 2: image hosting + MySQL + Redis

MySQL table:

CREATE TABLE `music_charts` (
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
*/

const IMAGE_HOST_JIKE = 'jike';
const IMAGE_HOST_OIL = 'oil';

function is_oil_url(string $url): bool {
    return $url !== '' && strpos($url, 'oilgasgpts.com') !== false;
}

function cli_get_param(string $key, string $default = ''): string {
    global $argv;
    foreach ($argv as $arg) {
        if (strpos($arg, $key . '=') === 0) {
            return substr($arg, strlen($key) + 1);
        }
    }
    return $default;
}

function load_progress(string $path): array {
    if ($path === '' || !is_file($path)) {
        return [];
    }
    $raw = (string)@file_get_contents($path);
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function save_progress(string $path, array $data): void {
    if ($path === '') return;
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $data['updated_at'] = gmdate('c');
    $tmp = $path . '.tmp';
    $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) return;
    @file_put_contents($tmp, $payload);
    @rename($tmp, $path);
}

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

function env_get(string $key, string $default = ''): string {
    $v = getenv($key);
    if ($v !== false) {
        return (string)$v;
    }

    global $APP_CONFIG;
    if (is_array($APP_CONFIG) && array_key_exists($key, $APP_CONFIG)) {
        $vv = $APP_CONFIG[$key];
        if (is_bool($vv)) {
            return $vv ? '1' : '0';
        }
        if (is_scalar($vv)) {
            return (string)$vv;
        }
    }
    return $default;
}

function out_json(int $code, string $msg, $data = null): void {
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'code' => $code,
        'msg' => $msg,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function http_request(string $url, string $method = 'GET', array $headers = [], string $cookie = '', ?string $body = null): string {
    $method = strtoupper($method);
    if (!function_exists('curl_init')) {
        throw new RuntimeException('ext-curl is required for Step 2 (download/upload).');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }

    $hdrs = $headers;
    if ($cookie !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);

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

function download_to_temp(string $url, string $tmpDir): string {
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
        throw new RuntimeException('Failed to open temp file for writing: ' . $tmpFile);
    }

    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0',
        'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
        'Referer: ' . (parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . '/'),
    ]);

    $ok = curl_exec($ch);
    if ($ok === false) {
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        @unlink($tmpFile);
        throw new RuntimeException('Cover download failed: ' . $err);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if ($status < 200 || $status >= 300) {
        @unlink($tmpFile);
        throw new RuntimeException('Cover download HTTP status ' . $status . ' for ' . $url);
    }
    if (!is_file($tmpFile) || filesize($tmpFile) === 0) {
        @unlink($tmpFile);
        throw new RuntimeException('Cover download produced empty file');
    }

    return $tmpFile;
}

function upload_to_image_host_jike(string $localFilePath): string {
    $url = 'http://www.jiketianqi.com/weatapi/file/upload';
    $post = [
        'files' => new CURLFile($localFilePath),
    ];
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
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Jike upload HTTP status ' . $status);
    }

    $json = json_decode((string)$resp, true);
    if (!is_array($json) || !isset($json['data']) || !is_array($json['data']) || !isset($json['data'][0])) {
        throw new RuntimeException('Jike upload response parse failed');
    }
    $u = (string)$json['data'][0];
    if ($u === '') {
        throw new RuntimeException('Jike upload returned empty url');
    }
    return $u;
}

function upload_to_image_host_oil(string $localFilePath): string {
    $url = 'https://cn.oilgasgpts.com/file/upload';
    $post = [
        'file' => new CURLFile($localFilePath),
    ];
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
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Oil upload HTTP status ' . $status);
    }

    $json = json_decode((string)$resp, true);
    if (!is_array($json) || !isset($json['msg'])) {
        throw new RuntimeException('Oil upload response parse failed');
    }
    $u = (string)$json['msg'];
    if ($u === '') {
        throw new RuntimeException('Oil upload returned empty url');
    }
    return $u;
}

function upload_to_image_host(string $localFilePath, string &$rrState): string {
    $rrState = IMAGE_HOST_JIKE;
    return upload_to_image_host_jike($localFilePath);
}

function pdo_connect(string $dsn, string $user, string $pass): PDO {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ]);
    return $pdo;
}

function mysql_maybe_migrate(PDO $pdo, bool $auto): void {
    if (!$auto) {
        return;
    }
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `music_charts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source` varchar(10) NOT NULL COMMENT 'enum: qq, wyy',
  `type` varchar(20) NOT NULL COMMENT 'enum: hot, soaring',
  `title` varchar(255) NOT NULL,
  `artist` varchar(255) NOT NULL,
  `original_share_url` varchar(500) NOT NULL,
  `original_cover_url` text COMMENT 'source cover url',
  `hosted_cover_url` text COMMENT 'uploaded cover url',
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_source_type` (`source`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    $pdo->exec($sql);
}

function mysql_load_hosted_map(PDO $pdo, string $source, string $type): array {
    $stmt = $pdo->prepare('SELECT original_share_url, hosted_cover_url FROM music_charts WHERE source=? AND type=? AND hosted_cover_url IS NOT NULL');
    $stmt->execute([$source, $type]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $k = (string)($row['original_share_url'] ?? '');
        $v = (string)($row['hosted_cover_url'] ?? '');
        if ($k !== '' && $v !== '') {
            $map[$k] = $v;
        }
    }
    return $map;
}

function mysql_replace_chart(PDO $pdo, string $source, string $type, array $rows): void {
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM music_charts WHERE source=? AND type=?');
        $del->execute([$source, $type]);

        if (count($rows) === 0) {
            $pdo->commit();
            return;
        }

        $cols = '(source,type,title,artist,original_share_url,original_cover_url,hosted_cover_url)';
        $place = '(?,?,?,?,?,?,?)';
        $chunks = [];
        $vals = [];
        foreach ($rows as $r) {
            $chunks[] = $place;
            $vals[] = $source;
            $vals[] = $type;
            $vals[] = (string)$r['title'];
            $vals[] = (string)$r['artist'];
            $vals[] = (string)$r['original_share_url'];
            $vals[] = (string)($r['original_cover_url'] ?? '');
            $vals[] = (string)($r['hosted_cover_url'] ?? '');
        }
        $sql = 'INSERT INTO music_charts ' . $cols . ' VALUES ' . implode(',', $chunks);
        $ins = $pdo->prepare($sql);
        $ins->execute($vals);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function redis_connect(string $host, int $port, int $db, string $pass): Redis {
    if (!class_exists('Redis')) {
        throw new RuntimeException('ext-redis is required for Step 2 (Redis cache).');
    }
    $r = new Redis();
    if (!$r->connect($host, $port, 3.0)) {
        throw new RuntimeException('Redis connect failed');
    }
    if ($pass !== '') {
        if (!$r->auth($pass)) {
            throw new RuntimeException('Redis auth failed');
        }
    }
    if ($db !== 0) {
        if (!$r->select($db)) {
            throw new RuntimeException('Redis select failed');
        }
    }
    return $r;
}

function redis_set_chart(Redis $r, string $source, string $type, array $chart, int $ttlSeconds): void {
    $key = 'chart:' . $source . ':' . $type;
    $payload = json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('Redis json_encode failed');
    }
    if ($ttlSeconds > 0) {
        $r->setex($key, $ttlSeconds, $payload);
    } else {
        $r->set($key, $payload);
    }
}

function run_crawler(string $phpBin, string $mode): array {
    $script = __DIR__ . '/crawl_charts.php';
    if (!is_file($script)) {
        throw new RuntimeException('Missing crawler: ' . $script);
    }
    $cmd = escapeshellcmd($phpBin) . ' ' . escapeshellarg($script) . ' mode=' . escapeshellarg($mode);
    $out = shell_exec($cmd);
    if (!is_string($out) || trim($out) === '') {
        throw new RuntimeException('Crawler produced empty output');
    }
    $json = json_decode($out, true);
    if (!is_array($json) || !isset($json['code'])) {
        throw new RuntimeException('Crawler output is not valid JSON');
    }
    if ((int)$json['code'] !== 200) {
        throw new RuntimeException('Crawler error: ' . (string)($json['msg'] ?? 'unknown'));
    }
    $data = $json['data'] ?? null;
    if (!is_array($data) || !isset($data['charts']) || !is_array($data['charts'])) {
        throw new RuntimeException('Crawler output missing charts');
    }
    return $data['charts'];
}

function assert_chart_shape(array $song): void {
    foreach (['title', 'artist', 'original_share_url', 'original_cover_url'] as $k) {
        if (!array_key_exists($k, $song)) {
            throw new RuntimeException('Song missing key: ' . $k);
        }
    }
}

function chart_fingerprint(array $songs): string {
    $parts = [];
    foreach ($songs as $song) {
        if (!is_array($song)) continue;
        $u = isset($song['original_share_url']) ? (string)$song['original_share_url'] : '';
        if ($u !== '') {
            $parts[] = $u;
        }
    }
    return md5(implode("\n", $parts));
}

function mysql_fetch_chart_rows(PDO $pdo, string $source, string $type, int $limit = 200): array {
    $stmt = $pdo->prepare('SELECT title, artist, original_share_url, original_cover_url, hosted_cover_url FROM music_charts WHERE source=? AND type=? ORDER BY id ASC LIMIT ' . (int)$limit);
    $stmt->execute([$source, $type]);
    $rows = $stmt->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'title' => (string)($r['title'] ?? ''),
            'artist' => (string)($r['artist'] ?? ''),
            'original_share_url' => (string)($r['original_share_url'] ?? ''),
            'original_cover_url' => (string)($r['original_cover_url'] ?? ''),
            'hosted_cover_url' => (string)($r['hosted_cover_url'] ?? ''),
        ];
    }
    return $out;
}

function main(): void {
    $dryRun = (cli_get_param('dry_run', env_get('DRY_RUN', '0')) === '1');
    $crawlerMode = cli_get_param('crawler_mode', env_get('CRAWLER_MODE', 'live')); // live|sample
    $phpBin = cli_get_param('php_bin', env_get('PHP_BIN', 'php'));

    $mysqlDsn = cli_get_param('mysql_dsn', env_get('MYSQL_DSN', ''));
    $mysqlUser = cli_get_param('mysql_user', env_get('MYSQL_USER', ''));
    $mysqlPass = cli_get_param('mysql_pass', env_get('MYSQL_PASS', ''));
    $mysqlAutoMigrate = (cli_get_param('mysql_auto_migrate', env_get('MYSQL_AUTO_MIGRATE', '0')) === '1');

    $redisHost = cli_get_param('redis_host', env_get('REDIS_HOST', '127.0.0.1'));
    $redisPort = (int)cli_get_param('redis_port', env_get('REDIS_PORT', '6379'));
    $redisDb = (int)cli_get_param('redis_db', env_get('REDIS_DB', '0'));
    $redisPass = cli_get_param('redis_pass', env_get('REDIS_PASS', ''));
    $redisTtl = (int)cli_get_param('redis_ttl', env_get('REDIS_TTL', '600'));

    $tmpDir = cli_get_param('tmp_dir', env_get('TMP_DIR', sys_get_temp_dir()));
    $rrState = cli_get_param('upload_rr', env_get('UPLOAD_RR', IMAGE_HOST_JIKE));
    $progressFile = cli_get_param('progress_file', env_get('PROGRESS_FILE', ''));

    try {
        $progress = load_progress($progressFile);
        $progress['progress_file'] = $progressFile;
        if (!isset($progress['started_at'])) {
            $progress['started_at'] = gmdate('c');
        }
        if (!isset($progress['stages']) || !is_array($progress['stages'])) {
            $progress['stages'] = [];
        }
        if (!isset($progress['fingerprints']) || !is_array($progress['fingerprints'])) {
            $progress['fingerprints'] = [];
        }
        if (!isset($progress['songs']) || !is_array($progress['songs'])) {
            $progress['songs'] = [];
        }

        $charts = run_crawler($phpBin, $crawlerMode);

        if ($dryRun) {
            $counts = [];
            foreach ($charts as $source => $types) {
                foreach ($types as $type => $songs) {
                    $counts[$source . ':' . $type] = is_array($songs) ? count($songs) : 0;
                }
            }
            out_json(200, 'dry_run_ok', [
                'crawler_mode' => $crawlerMode,
                'counts' => $counts,
            ]);
            return;
        }

        if ($mysqlDsn === '' || $mysqlUser === '') {
            throw new RuntimeException('MySQL config missing. Provide MYSQL_DSN and MYSQL_USER (and MYSQL_PASS if needed).');
        }

        $pdo = pdo_connect($mysqlDsn, $mysqlUser, $mysqlPass);
        mysql_maybe_migrate($pdo, $mysqlAutoMigrate);
        $redis = redis_connect($redisHost, $redisPort, $redisDb, $redisPass);

        $processed = [];
        $changedStages = [];
        $skippedStages = [];

        foreach (['qq', 'wyy'] as $source) {
            if (!isset($charts[$source]) || !is_array($charts[$source])) {
                continue;
            }
            foreach (['hot', 'soaring'] as $type) {
                $songs = $charts[$source][$type] ?? [];
                if (!is_array($songs)) {
                    $songs = [];
                }

                $stageKey = $source . ':' . $type;
                $fpNow = chart_fingerprint($songs);
                $fpPrev = isset($progress['fingerprints'][$stageKey]) ? (string)$progress['fingerprints'][$stageKey] : '';
                $prevStage = isset($progress['stages'][$stageKey]) && is_array($progress['stages'][$stageKey]) ? $progress['stages'][$stageKey] : [];
                $prevDone = isset($prevStage['status']) && (string)$prevStage['status'] === 'done';

                // If the chart list did not change and we had a previous successful run, skip heavy work.
                if ($fpNow !== '' && $fpPrev !== '' && $fpNow === $fpPrev && $prevDone) {
                    $progress['stages'][$stageKey] = [
                        'status' => 'up_to_date',
                        'total' => count($songs),
                        'processed' => count($songs),
                    ];
                    $skippedStages[] = $stageKey;
                    save_progress($progressFile, $progress);

                    // Still refresh Redis cache (TTL may expire), using DB rows.
                    $rows = mysql_fetch_chart_rows($pdo, $source, $type, 200);
                    $cachePayload = [
                        'source' => $source,
                        'type' => $type,
                        'updated_at' => gmdate('c'),
                        'list' => $rows,
                    ];
                    redis_set_chart($redis, $source, $type, $cachePayload, $redisTtl);
                    $processed[$stageKey] = [
                        'rows' => count($rows),
                        'uploads' => 0,
                        'reused' => 0,
                        'skipped' => true,
                    ];
                    continue;
                }

                $hostedMap = mysql_load_hosted_map($pdo, $source, $type);
                $rows = [];
                $uploads = 0;
                $reused = 0;

                $changedStages[] = $stageKey;
                if ($fpNow !== '') {
                    $progress['fingerprints'][$stageKey] = $fpNow;
                }

                $progress['stages'][$stageKey] = [
                    'status' => 'running',
                    'total' => is_array($songs) ? count($songs) : 0,
                    'processed' => 0,
                ];
                save_progress($progressFile, $progress);

                foreach ($songs as $song) {
                    if (!is_array($song)) {
                        continue;
                    }
                    assert_chart_shape($song);
                    $share = (string)$song['original_share_url'];

                    $songKey = $stageKey . '|' . md5($share);
                    $prev = isset($progress['songs'][$songKey]) && is_array($progress['songs'][$songKey]) ? $progress['songs'][$songKey] : [];

                    $hosted = '';
                    if ($share !== '' && isset($prev['hosted_cover_url']) && (string)$prev['hosted_cover_url'] !== '') {
                        $candidate = (string)$prev['hosted_cover_url'];
                        if (!is_oil_url($candidate)) {
                            $hosted = $candidate;
                            $reused++;
                        }
                    } elseif ($share !== '' && isset($hostedMap[$share])) {
                        $candidate = (string)$hostedMap[$share];
                        if (!is_oil_url($candidate)) {
                            $hosted = $candidate;
                            $reused++;
                        }
                    } else {
                        $coverUrl = (string)$song['original_cover_url'];
                        if ($coverUrl !== '') {
                            $tmpFile = download_to_temp($coverUrl, $tmpDir);
                            try {
                                $hosted = upload_to_image_host($tmpFile, $rrState);
                                $uploads++;
                            } finally {
                                @unlink($tmpFile);
                            }
                        }
                    }

                    // Persist per-song progress so an interrupted run can resume without re-uploading.
                    $progress['songs'][$songKey] = [
                        'share_url' => $share,
                        'hosted_cover_url' => $hosted,
                        'updated_at' => gmdate('c'),
                    ];

                    $rows[] = [
                        'title' => (string)$song['title'],
                        'artist' => (string)$song['artist'],
                        'original_share_url' => (string)$song['original_share_url'],
                        'original_cover_url' => (string)$song['original_cover_url'],
                        'hosted_cover_url' => $hosted,
                    ];

                    $progress['stages'][$stageKey]['processed'] = (int)$progress['stages'][$stageKey]['processed'] + 1;
                    // Throttle writes: still frequent enough for resume.
                    if (($progress['stages'][$stageKey]['processed'] % 3) === 0) {
                        save_progress($progressFile, $progress);
                    }
                }

                $progress['stages'][$stageKey]['status'] = 'writing_db';
                save_progress($progressFile, $progress);

                mysql_replace_chart($pdo, $source, $type, $rows);

                $cachePayload = [
                    'source' => $source,
                    'type' => $type,
                    'updated_at' => gmdate('c'),
                    'list' => $rows,
                ];
                redis_set_chart($redis, $source, $type, $cachePayload, $redisTtl);

                $progress['stages'][$stageKey]['status'] = 'done';
                save_progress($progressFile, $progress);

                $processed[$source . ':' . $type] = [
                    'rows' => count($rows),
                    'uploads' => $uploads,
                    'reused' => $reused,
                ];
            }
        }

        $progress['status'] = 'done';
        save_progress($progressFile, $progress);

        $allUpToDate = count($changedStages) === 0 && count($skippedStages) > 0;
        out_json(200, $allUpToDate ? 'up_to_date' : 'ok', [
            'processed' => $processed,
            'redis_ttl' => $redisTtl,
            'changed' => $changedStages,
            'skipped' => $skippedStages,
            'checked_at' => gmdate('c'),
        ]);
    } catch (Throwable $e) {
        if (isset($progress) && is_array($progress)) {
            $progress['status'] = 'error';
            $progress['error'] = $e->getMessage();
            save_progress($progressFile, $progress);
        }
        out_json(500, $e->getMessage(), null);
    }
}

main();
