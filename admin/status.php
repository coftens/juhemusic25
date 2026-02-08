<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

admin_require_auth();

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tail_lines(string $path, int $maxLines = 200): string {
    if (!is_file($path)) return '';
    $fp = @fopen($path, 'rb');
    if ($fp === false) return '';
    $size = (int)filesize($path);
    if ($size <= 0) {
        fclose($fp);
        return '';
    }
    $read = min($size, 64 * 1024);
    if ($read <= 0) {
        fclose($fp);
        return '';
    }
    @fseek($fp, max(0, $size - $read));
    $buf = (string)fread($fp, $read);
    fclose($fp);
    $buf = str_replace("\r\n", "\n", $buf);
    $lines = explode("\n", $buf);
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, -$maxLines);
    }
    return trim(implode("\n", $lines));
}

function read_json_file(string $path): array {
    if (!is_file($path)) return [];
    $raw = (string)@file_get_contents($path);
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

$name = isset($_GET['name']) ? (string)$_GET['name'] : '';
if (!in_array($name, ['ingest_charts', 'ingest_qq_home'], true)) {
    $name = 'ingest_charts';
}

$statePath = admin_logs_dir() . '/task_' . $name . '.json';
$state = read_json_file($statePath);
$pid = isset($state['pid']) ? (string)$state['pid'] : '';
$logFile = isset($state['log_file']) ? (string)$state['log_file'] : '';
$progressFile = isset($state['progress_file']) ? (string)$state['progress_file'] : '';

$logPath = $logFile !== '' ? (admin_logs_dir() . '/' . $logFile) : '';
$progressPath = $progressFile !== '' ? (admin_logs_dir() . '/' . $progressFile) : '';

$progress = $progressPath !== '' ? read_json_file($progressPath) : [];

admin_layout_header('任务状态');
echo '<div class="topnav">';
echo '<div><span class="tag">/admin</span> <span class="tag">status</span></div>';
echo '<div class="navlinks">';
echo '<a class="btn secondary" href="/admin/jobs.php">更新任务</a>';
echo '<a class="btn secondary" href="/admin/data.php">数据查看</a>';
echo '<a class="btn danger" href="/admin/logout.php">退出</a>';
echo '</div>';
echo '</div>';

echo '<div class="card">';
echo '<h1>任务状态 - ' . h($name) . '</h1>';
echo '<p><a class="btn secondary" href="/admin/status.php?name=' . h($name) . '">刷新</a></p>';
echo '<div class="mono">';
echo 'PID: ' . h($pid) . "\n";
echo 'started_at: ' . h((string)($state['started_at'] ?? '')) . "\n";
echo 'log: ' . h($logPath) . "\n";
echo 'progress: ' . h($progressPath) . "\n";
echo '</div>';
echo '</div>';

echo '<div style="height:12px"></div>';

if ($name === 'ingest_charts') {
    echo '<div class="card">';
    echo '<h1>进度</h1>';
    $stages = (isset($progress['stages']) && is_array($progress['stages'])) ? $progress['stages'] : [];
    if (!$stages) {
        echo '<p>暂无进度信息（任务可能还没开始写进度文件）。</p>';
    } else {
        echo '<div class="mono">';
        foreach (['qq:hot', 'qq:soaring', 'wyy:hot', 'wyy:soaring'] as $k) {
            $s = isset($stages[$k]) && is_array($stages[$k]) ? $stages[$k] : [];
            $status = (string)($s['status'] ?? '');
            $total = (int)($s['total'] ?? 0);
            $done = (int)($s['processed'] ?? 0);
            echo $k . '  status=' . $status . '  ' . $done . '/' . $total . "\n";
        }
        echo '</div>';
    }
    echo '</div>';
    echo '<div style="height:12px"></div>';
}

echo '<div class="card">';
echo '<h1>最近日志（最后 200 行）</h1>';
echo '<div class="mono">' . h($logPath !== '' ? tail_lines($logPath, 200) : '') . '</div>';
echo '</div>';

admin_layout_footer();
