<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'ingest_qq_home') {
            $cmd = 'php ' . escapeshellarg(dirname(__DIR__) . '/ingest_qq_home.php') . ' 2>&1';
            $output = shell_exec($cmd);
            $msg = "QQ Ingest complete. Result: " . $output;
        } elseif ($action === 'ingest_wyy_home') {
            $cmd = 'php ' . escapeshellarg(dirname(__DIR__) . '/ingest_wyy_home.php') . ' 2>&1';
            $output = shell_exec($cmd);
            $msg = "WYY Ingest complete. Result: " . $output;
        } elseif ($action === 'ingest_wyy_daily') {
            $cmd = 'php ' . escapeshellarg(dirname(__DIR__) . '/ingest_wyy_daily.php') . ' 2>&1';
            $output = shell_exec($cmd);
            $msg = "WYY Daily Songs complete. Result: " . $output;
        } elseif ($action === 'ingest_qishui_matched') {
            $count = (int)($_POST['count'] ?? 20);
            $cmd = 'php ' . escapeshellarg(dirname(__DIR__) . '/ingest_qishui_matched.php') . ' ' . $count . ' 2>&1';
            $output = shell_exec($cmd);
            $msg = "Qishui Discovery Flow complete (Count: $count). Result: " . $output;
        } elseif ($action === 'clear_home_data') {
            $pdo = pdo_connect_from_env();
            $pdo->exec('TRUNCATE TABLE music_home_items');
            
            // Also clear Redis cache
            $redisUrl = env_get('REDIS_URL', '');
            if ($redisUrl !== '') {
                try {
                    $parts = parse_url($redisUrl);
                    $host = $parts['host'] ?? '127.0.0.1';
                    $port = (int)($parts['port'] ?? 6379);
                    $redis = new Redis();
                    if ($redis->connect($host, $port)) {
                        $redis->del('home:qq:index');
                    }
                } catch (Throwable $e) {}
            }
            $msg = "Success: Database music_home_items cleared and Redis cache deleted.";
        }
    } catch (Throwable $e) {
        $msg = "Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Jobs - Music Admin</title>
    <style>
        body { font-family: sans-serif; padding: 20px; max-width: 800px; margin: 0 auto; line-height: 1.6; }
        .job-card { border: 1px solid #ccc; padding: 15px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .btn { padding: 10px 20px; cursor: pointer; background: #007bff; color: white; border: none; border-radius: 4px; font-weight: bold; }
        .btn:hover { opacity: 0.9; }
        .btn-danger { background: #dc3545; }
        .msg { background: #f8f9fa; border-left: 4px solid #007bff; padding: 15px; margin-bottom: 20px; white-space: pre-wrap; font-family: monospace; }
        h3 { margin-top: 0; }
    </style>
</head>
<body>
    <h1>System Jobs</h1>
    
    <?php if ($msg): ?>
        <div class="msg"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="job-card" style="border-color: #dc3545; background-color: #fff5f5;">
        <h3 style="color: #dc3545;">Database Cleanup</h3>
        <p>This will permanently remove all playlists and songs currently stored in the Home feed (<code>music_home_items</code> table). Use this to start fresh.</p>
        <form method="POST">
            <input type="hidden" name="action" value="clear_home_data">
            <button type="submit" class="btn btn-danger" onclick="return confirm('WARNING: This will delete ALL songs and playlists from the home screen. Are you sure?')">Clear All Home Data (Fresh Start)</button>
        </form>
    </div>

    <div class="job-card">
        <h3>Update QQ Music Home</h3>
        <p>Run the ingestion script to fetch the latest hot recommend data from QQ Music.</p>
        <form method="POST">
            <input type="hidden" name="action" value="ingest_qq_home">
            <button type="submit" class="btn">Update QQ Home Cache</button>
        </form>
    </div>

    <div class="job-card">
        <h3>Ingest WYY Home (Omni Mode)</h3>
        <p>Fetch personalized playlists and new songs from Netease Cloud Music using direct API.</p>
        <form method="POST">
            <input type="hidden" name="action" value="ingest_wyy_home">
            <button type="submit" class="btn">Update WYY Home Cache</button>
        </form>
    </div>

    <div class="job-card">
        <h3>Ingest WYY Daily Songs</h3>
        <p>Fetch your personal "Daily Recommendation" songs (Needs valid Cookie). Replaces the song list section.</p>
        <form method="POST">
            <input type="hidden" name="action" value="ingest_wyy_daily">
            <button type="submit" class="btn" style="background-color: #fd7e14;">Update Daily Songs</button>
        </form>
    </div>

    <div class="job-card">
        <h3>Ingest Qishui Discovery Flow</h3>
        <p>Fetch trending songs from Qishui Feed and find matched high-quality versions on QQ/WYY曲库. These will be used for infinite playback.</p>
        <form method="POST">
            <input type="hidden" name="action" value="ingest_qishui_matched">
            <div style="margin-bottom: 10px;">
                <label>Fetch Count:</label>
                <input type="number" name="count" value="20" min="1" max="200" style="padding: 5px; width: 60px;">
            </div>
            <button type="submit" class="btn" style="background-color: #28a745;">Synchronize Qishui Discovery</button>
        </form>
    </div>

    <div style="margin-top: 30px;">
        <a href="index.php">&larr; Back to Admin Home</a>
    </div>
</body>
</html>