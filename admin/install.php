<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

if (admin_is_installed()) {
    header('Location: /admin/login.php');
    exit;
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
if ($step < 1 || $step > 3) $step = 1;

$err = '';
$okMsg = '';

function check_ext(string $name): bool {
    return extension_loaded($name);
}

function can_write_path(string $path): bool {
    if (is_file($path)) {
        return is_writable($path);
    }
    $dir = dirname($path);
    return is_dir($dir) && is_writable($dir);
}

function test_tcp(string $host, int $port, int $timeoutSec = 1): bool {
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeoutSec);
    if (!is_resource($fp)) return false;
    fclose($fp);
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $step = isset($_POST['step']) ? (int)$_POST['step'] : $step;

    if ($step === 2) {
        // no-op, just proceed
        header('Location: /admin/install.php?step=2');
        exit;
    }

    if ($step === 3) {
        $mysqlDsn = trim((string)($_POST['MYSQL_DSN'] ?? ''));
        $mysqlUser = trim((string)($_POST['MYSQL_USER'] ?? ''));
        $mysqlPass = (string)($_POST['MYSQL_PASS'] ?? '');
        $redisHost = trim((string)($_POST['REDIS_HOST'] ?? '127.0.0.1'));
        $redisPort = (int)($_POST['REDIS_PORT'] ?? 6379);
        $redisDb = (int)($_POST['REDIS_DB'] ?? 0);
        $redisPass = (string)($_POST['REDIS_PASS'] ?? '');
        $pyBase = trim((string)($_POST['PY_BASE_URL'] ?? DEFAULT_PY_BASE_URL));
        $nodeBase = trim((string)($_POST['NODE_BASE_URL'] ?? 'http://127.0.0.1:3000'));
        $phpBin = trim((string)($_POST['PHP_BIN'] ?? 'php'));
        $adminPass = (string)($_POST['ADMIN_PASS'] ?? '');
        $adminIpAllow = trim((string)($_POST['ADMIN_IP_ALLOWLIST'] ?? ''));

        if ($mysqlDsn === '' || $mysqlUser === '') {
            $err = 'MySQL DSN/USER required.';
        } elseif ($adminPass === '' || strlen($adminPass) < 8) {
            $err = 'Admin password required (min 8 chars).';
        } elseif (!can_write_path(LOCAL_CONFIG_FILE)) {
            $err = 'Cannot write ' . LOCAL_CONFIG_FILE . ' (check permissions).';
        } elseif (!can_write_path(ADMIN_LOCK_FILE)) {
            $err = 'Cannot write ' . ADMIN_LOCK_FILE . ' (check permissions).';
        } else {
            // Test MySQL
            try {
                $pdo = new PDO($mysqlDsn, $mysqlUser, $mysqlPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
                ]);
                mysql_migrate_song_cache($pdo);
                mysql_migrate_home_items($pdo);
                mysql_migrate_lyrics($pdo);
                mysql_migrate_charts($pdo);
            } catch (Throwable $e) {
                $err = 'MySQL connect/migrate failed: ' . $e->getMessage();
            }

            if ($err === '') {
                // Test Redis (optional but recommended)
                if (class_exists('Redis')) {
                    try {
                        $r = new Redis();
                        if (!$r->connect($redisHost, $redisPort, 1.5)) {
                            throw new RuntimeException('connect failed');
                        }
                        if ($redisPass !== '' && !$r->auth($redisPass)) {
                            throw new RuntimeException('auth failed');
                        }
                        if ($redisDb !== 0 && !$r->select($redisDb)) {
                            throw new RuntimeException('select failed');
                        }
                        $r->close();
                    } catch (Throwable $e) {
                        $err = 'Redis connect failed: ' . $e->getMessage();
                    }
                }
            }

            if ($err === '') {
                // Light sanity checks for internal services
                $pyOk = test_tcp('127.0.0.1', (int)parse_url($pyBase, PHP_URL_PORT) ?: 8002, 1);
                $nodeOk = test_tcp('127.0.0.1', (int)parse_url($nodeBase, PHP_URL_PORT) ?: 3000, 1);
                if (!$pyOk) {
                    $okMsg .= "Note: Python service not reachable at {$pyBase}. ";
                }
                if (!$nodeOk) {
                    $okMsg .= "Note: Node service not reachable at {$nodeBase}. ";
                }

                $cfg = [
                    'MYSQL_DSN' => $mysqlDsn,
                    'MYSQL_USER' => $mysqlUser,
                    'MYSQL_PASS' => $mysqlPass,
                    'REDIS_HOST' => $redisHost,
                    'REDIS_PORT' => (string)$redisPort,
                    'REDIS_DB' => (string)$redisDb,
                    'REDIS_PASS' => $redisPass,
                    'PY_BASE_URL' => $pyBase,
                    'NODE_BASE_URL' => $nodeBase,
                    'PHP_BIN' => $phpBin,
                    'ADMIN_PASS_HASH' => password_hash($adminPass, PASSWORD_DEFAULT),
                    'ADMIN_IP_ALLOWLIST' => $adminIpAllow,
                ];

                $export = "<?php\nreturn " . var_export($cfg, true) . ";\n";
                if (file_put_contents(LOCAL_CONFIG_FILE, $export) === false) {
                    $err = 'Failed to write config.local.php';
                } else {
                    @chmod(LOCAL_CONFIG_FILE, 0640);
                    file_put_contents(ADMIN_LOCK_FILE, 'installed ' . gmdate('c'));
                    @chmod(ADMIN_LOCK_FILE, 0640);
                    header('Location: /admin/login.php');
                    exit;
                }
            }
        }
    }
}

admin_layout_header('聚合音乐后台安装');
echo '<div class="topnav"><div><span class="tag">/admin</span> <span class="tag">installer</span></div><div class="navlinks"></div></div>';

if ($err !== '') {
    echo '<div class="card" style="border-color:rgba(255,102,102,.35)"><p class="bad"><b>错误:</b> ' . htmlspecialchars($err, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></div><div style="height:12px"></div>';
}
if ($okMsg !== '') {
    echo '<div class="card" style="border-color:rgba(98,210,111,.25)"><p class="ok">' . htmlspecialchars($okMsg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></div><div style="height:12px"></div>';
}

if ($step === 1) {
    echo '<div class="card">';
    echo '<h1>安装向导 - 第 1/3 步</h1>';
    echo '<p>本向导会生成 config.local.php、初始化数据库表，并设置后台密码。</p>';

    $checks = [
        ['PHP ext: curl', check_ext('curl')],
        ['PHP ext: pdo_mysql', check_ext('pdo_mysql')],
        ['PHP ext: redis (recommended)', class_exists('Redis')],
        ['Can write ' . basename(LOCAL_CONFIG_FILE), can_write_path(LOCAL_CONFIG_FILE)],
        ['Can write admin lock', can_write_path(ADMIN_LOCK_FILE)],
    ];
    echo '<div class="mono">';
    foreach ($checks as [$label, $ok]) {
        echo ($ok ? '[OK] ' : '[!!] ') . $label . "\n";
    }
    echo '</div>';

    echo '<form method="post" style="margin-top:14px">';
    echo '<input type="hidden" name="csrf" value="' . htmlspecialchars(admin_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
    echo '<input type="hidden" name="step" value="2">';
    echo '<button class="btn" type="submit">继续</button>';
    echo '</form>';
    echo '</div>';
}

if ($step === 2) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    echo '<div class="card">';
    echo '<h1>安装向导 - 第 2/3 步</h1>';
    echo '<p>填写服务连接信息。宝塔环境下 MySQL/Redis 通常在 127.0.0.1。</p>';
    echo '<form method="post">';
    echo '<input type="hidden" name="csrf" value="' . htmlspecialchars(admin_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
    echo '<input type="hidden" name="step" value="3">';

    echo '<label>MYSQL_DSN</label>';
    echo '<input name="MYSQL_DSN" placeholder="mysql:host=127.0.0.1;port=3306;dbname=music" required>';
    echo '<label>MYSQL_USER</label>';
    echo '<input name="MYSQL_USER" placeholder="music" required>';
    echo '<label>MYSQL_PASS</label>';
    echo '<input name="MYSQL_PASS" type="password" placeholder="password">';

    echo '<div class="row" style="margin-top:12px">';
    echo '<div class="card">';
    echo '<h2>Redis</h2>';
    echo '<label>REDIS_HOST</label>';
    echo '<input name="REDIS_HOST" value="127.0.0.1">';
    echo '<label>REDIS_PORT</label>';
    echo '<input name="REDIS_PORT" value="6379">';
    echo '<label>REDIS_DB</label>';
    echo '<input name="REDIS_DB" value="0">';
    echo '<label>REDIS_PASS</label>';
    echo '<input name="REDIS_PASS" type="password">';
    echo '</div>';
    echo '<div class="card">';
    echo '<h2>Internal Services</h2>';
    echo '<label>PY_BASE_URL</label>';
    echo '<input name="PY_BASE_URL" value="' . htmlspecialchars(DEFAULT_PY_BASE_URL, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
    echo '<label>NODE_BASE_URL</label>';
    echo '<input name="NODE_BASE_URL" value="http://127.0.0.1:3000">';
    echo '<label>PHP_BIN（用于执行更新脚本）</label>';
    echo '<input name="PHP_BIN" value="php" placeholder="/www/server/php/82/bin/php">';
    echo '</div>';
    echo '</div>';

    echo '<div class="row" style="margin-top:12px">';
    echo '<div class="card">';
    echo '<h2>后台账号</h2>';
    echo '<label>ADMIN_PASS（至少 8 位）</label>';
    echo '<input name="ADMIN_PASS" type="password" required>';
    echo '<label>ADMIN_IP_ALLOWLIST（可选，逗号分隔）</label>';
    echo '<input name="ADMIN_IP_ALLOWLIST" placeholder="e.g. ' . htmlspecialchars($ip, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
    echo '<p>留空表示任何 IP 都能访问后台（仍需要密码）。强烈建议填写你自己的公网 IP。</p>';
    echo '</div>';
    echo '</div>';

    echo '<button class="btn" type="submit">写入配置并安装</button>';
    echo '</form>';
    echo '</div>';
}

admin_layout_footer();
