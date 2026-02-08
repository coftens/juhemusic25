<?php
declare(strict_types=1);

require __DIR__ . '/../php_api_common.php';

session_start();

const ADMIN_LOCK_FILE = __DIR__ . '/installed.lock';

function admin_is_installed(): bool {
    return is_file(LOCAL_CONFIG_FILE) && is_file(ADMIN_LOCK_FILE);
}

function admin_cfg(string $key, string $default = ''): string {
    return env_get($key, $default);
}

function admin_require_installed(): void {
    if (!admin_is_installed()) {
        header('Location: /admin/install.php');
        exit;
    }
}

function admin_password_hash(): string {
    return admin_cfg('ADMIN_PASS_HASH', '');
}

function admin_is_authed(): bool {
    return isset($_SESSION['admin_authed']) && $_SESSION['admin_authed'] === true;
}

function admin_require_auth(): void {
    admin_require_installed();

    // Optional IP allowlist
    $allow = admin_cfg('ADMIN_IP_ALLOWLIST', '');
    if ($allow !== '') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $parts = array_values(array_filter(array_map('trim', explode(',', $allow)), static fn($x) => $x !== ''));
        if ($ip === '' || !in_array($ip, $parts, true)) {
            http_response_code(403);
            echo '禁止访问';
            exit;
        }
    }

    if (!admin_is_authed()) {
        header('Location: /admin/login.php');
        exit;
    }
}

function admin_csrf_token(): string {
    if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || $_SESSION['csrf'] === '') {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['csrf'];
}

function admin_check_csrf(): void {
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || $t === '' || !hash_equals(admin_csrf_token(), $t)) {
        http_response_code(400);
        echo 'CSRF 校验失败';
        exit;
    }
}

function admin_layout_header(string $title): void {
    $safe = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo "<!doctype html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>{$safe}</title>";
    echo '<style>';
    echo 'body{margin:0;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;background:#0f1317;color:#e8eef6}';
    echo 'a{color:#9ad0ff;text-decoration:none}';
    echo '.wrap{max-width:980px;margin:0 auto;padding:22px}';
    echo '.card{background:#151b22;border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:16px}';
    echo '.row{display:flex;gap:14px;flex-wrap:wrap}';
    echo '.row>.card{flex:1;min-width:300px}';
    echo 'h1{font-size:20px;margin:0 0 10px}';
    echo 'h2{font-size:16px;margin:0 0 10px;color:#cfe2f6}';
    echo 'p{margin:8px 0;color:#b8c7da;line-height:1.45}';
    echo 'label{display:block;font-size:12px;color:#b8c7da;margin:10px 0 6px}';
    echo 'input,textarea,select{width:100%;box-sizing:border-box;background:#0f1317;color:#e8eef6;border:1px solid rgba(255,255,255,.10);border-radius:10px;padding:10px}';
    echo 'textarea{min-height:120px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px}';
    echo '.btn{display:inline-block;background:#2b7bb9;border:0;color:#fff;border-radius:10px;padding:10px 12px;font-weight:700;cursor:pointer}';
    echo '.btn.secondary{background:#2b3340}';
    echo '.btn.danger{background:#c23a3a}';
    echo '.topnav{display:flex;gap:10px;align-items:center;justify-content:space-between;margin-bottom:14px}';
    echo '.navlinks{display:flex;gap:10px;flex-wrap:wrap}';
    echo '.tag{display:inline-block;padding:3px 8px;border-radius:999px;background:rgba(154,208,255,.12);border:1px solid rgba(154,208,255,.24);color:#9ad0ff;font-size:12px}';
    echo '.ok{color:#62d26f} .bad{color:#ff6666}';
    echo '.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;white-space:pre-wrap;word-break:break-word;background:#0f1317;border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:10px}';
    echo '</style>';
    echo '</head><body><div class="wrap">';
}

function admin_layout_footer(): void {
    echo '</div></body></html>';
}

function admin_logs_dir(): string {
    return __DIR__ . '/logs';
}

function admin_write_log(string $prefix, string $content): string {
    $dir = admin_logs_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $name = $prefix . '_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.log';
    $path = $dir . '/' . $name;
    file_put_contents($path, $content);
    return $name;
}

function admin_can_exec(): bool {
    $disabled = (string)ini_get('disable_functions');
    $list = array_map('trim', explode(',', $disabled));
    foreach (['proc_open', 'shell_exec'] as $fn) {
        if (in_array($fn, $list, true)) {
            return false;
        }
    }
    return function_exists('proc_open');
}

function admin_run_cmd(array $argv, int $timeoutSec = 120): array {
    // argv[0] is binary; rest are args.
    $cmd = [];
    foreach ($argv as $a) {
        $cmd[] = escapeshellarg((string)$a);
    }
    $command = implode(' ', $cmd);

    $spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($command, $spec, $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        throw new RuntimeException('proc_open failed');
    }
    fclose($pipes[0]);

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $out = '';
    $err = '';
    $start = time();
    $exitCode = null;
    while (true) {
        $status = proc_get_status($proc);
        $out .= (string)stream_get_contents($pipes[1]);
        $err .= (string)stream_get_contents($pipes[2]);
        if (!$status['running']) {
            if (isset($status['exitcode']) && is_int($status['exitcode'])) {
                $exitCode = $status['exitcode'];
            }
            break;
        }
        if ((time() - $start) > $timeoutSec) {
            proc_terminate($proc);
            $exitCode = -1;
            break;
        }
        usleep(80 * 1000);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $code = (int)$code;
    if ($code === -1 && $exitCode !== null && is_int($exitCode) && $exitCode >= 0) {
        $code = $exitCode;
    }
    return ['code' => $code, 'stdout' => $out, 'stderr' => $err, 'cmd' => $command];
}
