<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

admin_require_auth();

$tab = isset($_GET['tab']) ? (string)$_GET['tab'] : 'charts';
if (!in_array($tab, ['charts', 'home_qq', 'home_wyy', 'home_wyy_daily', 'matched_feed', 'users'], true)) {
    $tab = 'charts';
}

function admin_redis_try_get(string $key): ?array {
    if (!class_exists('Redis')) return null;
    try {
        $host = env_get('REDIS_HOST', '127.0.0.1');
        $port = (int)env_get('REDIS_PORT', '6379');
        $db = (int)env_get('REDIS_DB', '0');
        $pass = env_get('REDIS_PASS', '');

        $r = new Redis();
        if (!$r->connect($host, $port, 2.0)) return null;
        if ($pass !== '' && !$r->auth($pass)) return null;
        if ($db !== 0 && !$r->select($db)) return null;
        $raw = $r->get($key);
        if (!is_string($raw) || $raw === '') return null;
        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    } catch (Throwable $e) {
        return null;
    }
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function render_table(array $rows): void {
    echo '<div style="overflow:auto">';
    echo '<table style="width:100%;border-collapse:collapse">';
    echo '<thead><tr>';
    echo '<th style="text-align:left;padding:8px;border-bottom:1px solid rgba(255,255,255,.10)">#</th>';
    echo '<th style="text-align:left;padding:8px;border-bottom:1px solid rgba(255,255,255,.10)">歌名</th>';
    echo '<th style="text-align:left;padding:8px;border-bottom:1px solid rgba(255,255,255,.10)">歌手</th>';
    echo '<th style="text-align:left;padding:8px;border-bottom:1px solid rgba(255,255,255,.10)">分享链接</th>';
    echo '</tr></thead><tbody>';
    $i = 0;
    foreach ($rows as $r) {
        $i++;
        $title = (string)($r['title'] ?? $r['name'] ?? '');
        $artist = (string)($r['artist'] ?? '');
        $share = (string)($r['share_url'] ?? ($r['original_share_url'] ?? ''));
        echo '<tr>';
        echo '<td style="padding:8px;border-bottom:1px solid rgba(255,255,255,.06);color:#9fb2c8">' . $i . '</td>';
        echo '<td style="padding:8px;border-bottom:1px solid rgba(255,255,255,.06)">' . h($title) . '</td>';
        echo '<td style="padding:8px;border-bottom:1px solid rgba(255,255,255,.06);color:#cfe2f6">' . h($artist) . '</td>';
        echo '<td style="padding:8px;border-bottom:1px solid rgba(255,255,255,.06)">';
        if ($share !== '') {
            echo '<a href="' . h($share) . '" target="_blank" rel="noreferrer">打开</a>';
        } else {
            echo '-';
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function render_user_list(array $rows): void {
    echo '<div style="overflow:auto">';
    echo '<table style="width:100%;border-collapse:collapse">';
    echo '<thead><tr>';
    echo '<th style="text-align:left;padding:8px;border-bottom:1px solid rgba(255,255,255,.10)">ID</th>';
    echo '<th style="text-align:left;padding:8px;border-bottom:1px solid rgba(255,255,255,.10)">用户</th>';
    echo '<th style="text-align:left;padding:8px;border-bottom:1px solid rgba(255,255,255,.10)">收藏</th>';
    echo '<th style="text-align:left;padding:8px;border-bottom:1px solid rgba(255,255,255,.10)">最近</th>';
    echo '<th style="text-align:left;padding:8px;border-bottom:1px solid rgba(255,255,255,.10)">歌单</th>';
    echo '<th style="text-align:left;padding:8px;border-bottom:1px solid rgba(255,255,255,.10)">今日听歌(s)</th>';
    echo '<th style="text-align:left;padding:8px;border-bottom:1px solid rgba(255,255,255,.10)">注册</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        $username = (string)($r['username'] ?? '');
        $avatar = (string)($r['avatar_path'] ?? '');
        $fav = (int)($r['favorites'] ?? 0);
        $rec = (int)($r['recents'] ?? 0);
        $pls = (int)($r['playlists'] ?? 0);
        $sec = (int)($r['today_seconds'] ?? 0);
        $created = (string)($r['created_at'] ?? '');

        echo '<tr>';
        echo '<td style="padding:8px;border-bottom:1px solid rgba(255,255,255,.06);color:#9fb2c8">' . $id . '</td>';
        echo '<td style="padding:8px;border-bottom:1px solid rgba(255,255,255,.06)">';
        echo '<div style="display:flex;align-items:center;gap:10px">';
        if ($avatar !== '') {
            echo '<img src="' . h($avatar) . '" alt="" style="width:28px;height:28px;border-radius:999px;object-fit:cover;border:1px solid rgba(255,255,255,.12)">';
        } else {
            echo '<div style="width:28px;height:28px;border-radius:999px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.12)"></div>';
        }
        echo '<div>'; 
        echo '<div style="font-weight:800">' . h($username) . '</div>';
        echo '<div class="mono" style="display:inline-block;padding:2px 6px;border-radius:8px;margin-top:3px">uid=' . $id . '</div>';
        echo '</div>';
        echo '</div>';
        echo '<div style="margin-top:6px"><a class="btn secondary" href="/admin/data.php?tab=users&user_id=' . $id . '">查看</a></div>';
        echo '</td>';
        echo '<td style="padding:8px;border-bottom:1px solid rgba(255,255,255,.06)">' . $fav . '</td>';
        echo '<td style="padding:8px;border-bottom:1px solid rgba(255,255,255,.06)">' . $rec . '</td>';
        echo '<td style="padding:8px;border-bottom:1px solid rgba(255,255,255,.06)">' . $pls . '</td>';
        echo '<td style="padding:8px;border-bottom:1px solid rgba(255,255,255,.06)">' . $sec . '</td>';
        echo '<td style="padding:8px;border-bottom:1px solid rgba(255,255,255,.06);color:#cfe2f6">' . h($created) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function mysql_has_table(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

admin_layout_header('数据查看');
echo '<div class="topnav">';
echo '<div><span class="tag">/admin</span> <span class="tag">data</span></div>';
echo '<div class="navlinks">';
echo '<a class="btn secondary" href="/admin/index.php">后台首页</a>';
echo '<a class="btn secondary" href="/admin/cookies.php">Cookie管理</a>';
echo '<a class="btn secondary" href="/admin/jobs.php">更新任务</a>';
echo '<a class="btn danger" href="/admin/logout.php">退出</a>';
echo '</div>';
echo '</div>';

echo '<div class="card">';
echo '<h1>数据查看</h1>';
echo '<p>';
echo '<a class="btn secondary" href="/admin/data.php?tab=charts">榜单</a> ';
echo '<a class="btn secondary" href="/admin/data.php?tab=home_qq">QQ 首页</a> ';
echo '<a class="btn secondary" href="/admin/data.php?tab=home_wyy">网易云首页</a> ';
echo '<a class="btn secondary" href="/admin/data.php?tab=home_wyy_daily">网易云日推</a> ';
echo '<a class="btn secondary" href="/admin/data.php?tab=matched_feed" style="color:#20c997">汽水发现流</a> ';
echo '<a class="btn secondary" href="/admin/data.php?tab=users">用户</a>';
echo '</p>';
echo '</div>';
echo '<div style="height:12px"></div>';

if ($tab === 'matched_feed') {
    echo '<div class="card">';
    echo '<h1>汽水发现流（已匹配 QQ/网易云）</h1>';
    try {
        $pdo = pdo_connect_from_env();
        $stmt = $pdo->prepare("SELECT source, item_id, title, subtitle, original_share_url, original_cover_url FROM music_home_items WHERE section='matchedFeed' ORDER BY id DESC LIMIT 200");
        $stmt->execute();
        $rows = $stmt->fetchAll();

        echo '<table style="width:100%;border-collapse:collapse">';
        echo '<thead><tr style="background:rgba(255,255,255,0.05)">';
        echo '<th style="padding:10px;text-align:left;border-bottom:1px solid #444">汽水原始信息 (标题 - 歌手)</th>';
        echo '<th style="padding:10px;text-align:left;border-bottom:1px solid #444">源</th>';
        echo '<th style="padding:10px;text-align:left;border-bottom:1px solid #444">匹配到的信息 (标题 - 歌手)</th>';
        echo '<th style="padding:10px;text-align:left;border-bottom:1px solid #444">操作</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $r) {
            $parts = explode(" |QS| ", (string)$r['subtitle']);
            $matchedArtist = $parts[0] ?? '-';
            $originalInfo = $parts[1] ?? '未知';
            $color = $r['source'] === 'qq' ? '#00d1b2' : '#e04a3a';

            echo '<tr>';
            echo '<td style="padding:10px;border-bottom:1px solid #333;color:#999">' . h($originalInfo) . '</td>';
            echo '<td style="padding:10px;border-bottom:1px solid #333"><span class="tag" style="background:'.$color.'">' . strtoupper($r['source']) . '</span></td>';
            echo '<td style="padding:10px;border-bottom:1px solid #333"><strong>' . h($r['title']) . '</strong><br><small style="color:#aaa">' . h($matchedArtist) . '</small></td>';
            echo '<td style="padding:10px;border-bottom:1px solid #333"><a href="'.h($r['original_share_url']).'" target="_blank">链接</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } catch (Throwable $e) {
        echo '<p class="bad">Error: ' . h($e->getMessage()) . '</p>';
    }
    echo '</div>';
}

if ($tab === 'charts') {
    echo '<div class="row">';

    $pairs = [
        ['qq', 'hot', 'QQ 热歌榜'],
        ['qq', 'soaring', 'QQ 飙升榜'],
        ['wyy', 'hot', '网易云 热歌榜'],
        ['wyy', 'soaring', '网易云 飙升榜'],
    ];

    foreach ($pairs as [$source, $type, $label]) {
        $key = 'chart:' . $source . ':' . $type;
        $from = 'redis';
        $payload = admin_redis_try_get($key);
        $list = null;
        $updated = '';
        if (is_array($payload) && isset($payload['list']) && is_array($payload['list'])) {
            $list = $payload['list'];
            $updated = isset($payload['updated_at']) ? (string)$payload['updated_at'] : '';
        }

        if ($list === null) {
            $from = 'mysql';
            try {
                $pdo = pdo_connect_from_env();
                mysql_migrate_charts($pdo);
                $stmt = $pdo->prepare('SELECT title, artist, original_share_url, original_cover_url, hosted_cover_url FROM music_charts WHERE source=? AND type=? ORDER BY id ASC LIMIT 200');
                $stmt->execute([$source, $type]);
                $rows = $stmt->fetchAll();
                $tmp = [];
                foreach ($rows as $r) {
                    $cover = (string)($r['hosted_cover_url'] ?? '');
                    if ($cover === '') $cover = (string)($r['original_cover_url'] ?? '');
                    $tmp[] = [
                        'title' => (string)($r['title'] ?? ''),
                        'artist' => (string)($r['artist'] ?? ''),
                        'share_url' => (string)($r['original_share_url'] ?? ''),
                        'cover_url' => $cover,
                    ];
                }
                $list = $tmp;
            } catch (Throwable $e) {
                $list = [];
            }
        }

        echo '<div class="card">';
        echo '<h2>' . h($label) . '</h2>';
        echo '<p>来源: <span class="tag">' . h($from) . '</span>';
        if ($updated !== '') {
            echo ' 更新时间: <span class="mono">' . h($updated) . '</span>';
        }
        echo '</p>';
        render_table(array_slice($list, 0, 100));
        echo '</div>';
    }

    echo '</div>';
}

if ($tab === 'home_qq' || $tab === 'home_wyy') {
    $source = ($tab === 'home_qq') ? 'qq' : 'wyy';
    $label = ($source === 'qq') ? 'QQ 首页数据' : '网易云首页数据';
    
    $key = env_get('HOME_REDIS_KEY', 'home:qq:index');
    $from = 'mysql';
    $payload = null;

    // Only try Redis for QQ
    if ($source === 'qq') {
        $payload = admin_redis_try_get($key);
        if ($payload) $from = 'redis';
    }

    if (!is_array($payload)) {
        $from = 'mysql';
        try {
            $pdo = pdo_connect_from_env();
            mysql_migrate_home_items($pdo);
            $stmt = $pdo->prepare('SELECT section, item_type, item_id, title, subtitle, metric, original_share_url, original_cover_url, hosted_cover_url FROM music_home_items WHERE source=? ORDER BY section, id DESC');
            $stmt->execute([$source]);
            $rows = $stmt->fetchAll();
            $sections = [
                'hotRecommend' => [],
                'newSonglist' => [],
                'hotCategory' => [],
                'newLanList' => [],
            ];
            foreach ($rows as $r) {
                $sec = (string)$r['section'];
                $cover = (string)($r['hosted_cover_url'] ?? '');
                if ($cover === '') {
                    $cover = (string)($r['original_cover_url'] ?? '');
                }
                if ($sec === 'hotRecommend') {
                    $sections[$sec][] = [
                        'id' => (string)$r['item_id'],
                        'title' => (string)$r['title'],
                        'play_count' => (int)($r['metric'] ?? 0),
                        'share_url' => (string)$r['original_share_url'],
                        'cover_url' => $cover,
                    ];
                } elseif ($sec === 'newSonglist') {
                    $sections[$sec][] = [
                        'mid' => (string)$r['item_id'],
                        'title' => (string)$r['title'],
                        'artist' => (string)($r['subtitle'] ?? ''),
                        'share_url' => (string)$r['original_share_url'],
                        'cover_url' => $cover,
                    ];
                } else {
                    $sections[$sec][] = [
                        'id' => (string)$r['item_id'],
                        'name' => (string)$r['title'],
                    ];
                }
            }
            $payload = [
                'generated_at' => gmdate('c'),
                'source' => $source,
                'sections' => $sections,
            ];
        } catch (Throwable $e) {
            $payload = ['error' => $e->getMessage()];
        }
    }

    $gen = isset($payload['generated_at']) ? (string)$payload['generated_at'] : '';
    $sections = (isset($payload['sections']) && is_array($payload['sections'])) ? $payload['sections'] : [];

    echo '<div class="card">';
    echo '<h1>' . h($label) . '</h1>';
    echo '<p>来源: <span class="tag">' . h($from) . '</span>';
    if ($from === 'redis') {
        echo ' RedisKey: <span class="mono">' . h($key) . '</span>';
    }
    echo '</p>';
    if ($gen !== '') {
        echo '<p>生成时间: <span class="mono">' . h($gen) . '</span></p>';
    }

    $counts = [];
    foreach (['hotRecommend', 'newSonglist', 'hotCategory', 'newLanList'] as $k) {
        $counts[$k] = (isset($sections[$k]) && is_array($sections[$k])) ? count($sections[$k]) : 0;
    }
    echo '<div class="mono">';
    echo 'hotRecommend: ' . $counts['hotRecommend'] . "\n";
    echo 'newSonglist: ' . $counts['newSonglist'] . "\n";
    echo 'hotCategory: ' . $counts['hotCategory'] . "\n";
    echo 'newLanList: ' . $counts['newLanList'] . "\n";
    echo '</div>';

    echo '<div style="height:12px"></div>';
    echo '<h2>热门歌单（前 20 条）</h2>';
    $hot = (isset($sections['hotRecommend']) && is_array($sections['hotRecommend'])) ? $sections['hotRecommend'] : [];
    render_table(array_map(static function($x) {
        return [
            'title' => (string)$x['title'],
            'artist' => '播放: ' . (string)($x['play_count'] ?? 0),
            'share_url' => (string)$x['share_url'],
        ];
    }, array_slice($hot, 0, 20)));

    echo '<div style="height:12px"></div>';
    echo '<h2>新歌推荐（前 50 条）</h2>';
    $songs = (isset($sections['newSonglist']) && is_array($sections['newSonglist'])) ? $sections['newSonglist'] : [];
    render_table(array_slice($songs, 0, 50));

    echo '<div style="height:12px"></div>';
    echo '<h2>原始 JSON（截断）</h2>';
    $j = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($j)) $j = '';
    if (strlen($j) > 12000) {
        $j = substr($j, 0, 12000) . "\n... (截断)";
    }
    echo '<div class="mono">' . h($j) . '</div>';
    echo '</div>';
}

if ($tab === 'home_wyy_daily') {
    echo '<div class="card">';
    echo '<h1>网易云每日推荐歌曲 (Daily Songs)</h1>';
    
    try {
        $pdo = pdo_connect_from_env();
        mysql_migrate_home_items($pdo);
        
        $stmt = $pdo->prepare("SELECT item_id, title, subtitle, original_share_url, original_cover_url FROM music_home_items WHERE source='wyy' AND section='newSonglist' ORDER BY id ASC");
        $stmt->execute();
        $rows = $stmt->fetchAll();
        
        echo '<p>共 ' . count($rows) . ' 首推荐歌曲 (Source: MySQL)</p>';
        echo '<div style="height:12px"></div>';
        
        render_table(array_map(static function($r) {
            return [
                'title' => (string)$r['title'],
                'artist' => (string)$r['subtitle'],
                'share_url' => (string)$r['original_share_url'],
                'cover_url' => (string)$r['original_cover_url'],
            ];
        }, $rows));
        
    } catch (Throwable $e) {
        echo '<p class="bad">Error: ' . h($e->getMessage()) . '</p>';
    }
    echo '</div>';
}

if ($tab === 'users') {
    $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    echo '<div class="card">';
    echo '<h1>用户数据</h1>';

    try {
        $pdo = pdo_connect_from_env();
        // Ensure tables exist.
        mysql_migrate_users($pdo);
        mysql_migrate_user_favorites($pdo);
        mysql_migrate_user_recents($pdo);
        mysql_migrate_user_playlists($pdo);
        mysql_migrate_user_playlist_tracks($pdo);
        mysql_migrate_listening_daily($pdo);
    } catch (Throwable $e) {
        echo '<p class="bad">数据库连接失败：' . h($e->getMessage()) . '</p>';
        echo '</div>';
        admin_layout_footer();
        exit;
    }

    if ($userId > 0) {
        // Detail view
        $u = null;
        try {
            $stmt = $pdo->prepare('SELECT id, username, avatar_path, created_at, updated_at FROM music_users WHERE id=? LIMIT 1');
            $stmt->execute([$userId]);
            $u = $stmt->fetch();
        } catch (Throwable $e) {
            $u = null;
        }
        if (!is_array($u)) {
            echo '<p class="bad">用户不存在</p>';
            echo '<p><a class="btn secondary" href="/admin/data.php?tab=users">返回用户列表</a></p>';
            echo '</div>';
            admin_layout_footer();
            exit;
        }

        echo '<p><a class="btn secondary" href="/admin/data.php?tab=users">返回用户列表</a></p>';

        $username = (string)($u['username'] ?? '');
        $avatar = (string)($u['avatar_path'] ?? '');
        $created = (string)($u['created_at'] ?? '');
        $updated = (string)($u['updated_at'] ?? '');
        echo '<div style="display:flex;align-items:center;gap:12px">';
        if ($avatar !== '') {
            echo '<img src="' . h($avatar) . '" alt="" style="width:56px;height:56px;border-radius:999px;object-fit:cover;border:1px solid rgba(255,255,255,.12)">';
        } else {
            echo '<div style="width:56px;height:56px;border-radius:999px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.12)"></div>';
        }
        echo '<div>';
        echo '<div style="font-size:18px;font-weight:900">' . h($username) . '</div>';
        echo '<div class="mono">uid=' . $userId . '</div>';
        echo '</div>';
        echo '</div>';

        // Stats
        $favCount = 0;
        $recentCount = 0;
        $playlistCount = 0;
        $todaySeconds = 0;
        $totalSeconds = 0;
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM music_user_favorites WHERE user_id=?');
            $stmt->execute([$userId]);
            $favCount = (int)($stmt->fetch()['c'] ?? 0);

            $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM music_user_recents WHERE user_id=?');
            $stmt->execute([$userId]);
            $recentCount = (int)($stmt->fetch()['c'] ?? 0);

            $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM music_user_playlists WHERE user_id=?');
            $stmt->execute([$userId]);
            $playlistCount = (int)($stmt->fetch()['c'] ?? 0);

            $stmt = $pdo->prepare('SELECT seconds FROM music_listening_daily WHERE user_id=? AND day=? LIMIT 1');
            $stmt->execute([$userId, gmdate('Y-m-d')]);
            $todaySeconds = (int)(($stmt->fetch()['seconds'] ?? 0));

            $stmt = $pdo->prepare('SELECT SUM(seconds) AS s FROM music_listening_daily WHERE user_id=?');
            $stmt->execute([$userId]);
            $totalSeconds = (int)($stmt->fetch()['s'] ?? 0);
        } catch (Throwable $e) {
            // ignore
        }

        echo '<div style="height:10px"></div>';
        echo '<div class="row">';
        echo '<div class="card"><h2>统计</h2>';
        echo '<div class="mono">收藏: ' . $favCount . "\n";
        echo '最近: ' . $recentCount . "\n";
        echo '歌单: ' . $playlistCount . "\n";
        echo '今日听歌(s): ' . $todaySeconds . "\n";
        echo '累计听歌(s): ' . $totalSeconds . "\n";
        echo '注册: ' . h($created) . "\n";
        echo '更新: ' . h($updated) . "\n";
        echo '</div></div>';

        // Favorites preview
        echo '<div class="card"><h2>收藏（前 50）</h2>';
        try {
            $stmt = $pdo->prepare('SELECT name AS title, artist, share_url FROM music_user_favorites WHERE user_id=? ORDER BY created_at DESC LIMIT 50');
            $stmt->execute([$userId]);
            render_table($stmt->fetchAll());
        } catch (Throwable $e) {
            echo '<p class="bad">读取失败</p>';
        }
        echo '</div>';

        // Recents preview
        echo '<div class="card"><h2>最近播放（前 50）</h2>';
        try {
            $stmt = $pdo->prepare('SELECT name AS title, artist, share_url FROM music_user_recents WHERE user_id=? ORDER BY last_played_at DESC LIMIT 50');
            $stmt->execute([$userId]);
            render_table($stmt->fetchAll());
        } catch (Throwable $e) {
            echo '<p class="bad">读取失败</p>';
        }
        echo '</div>';

        // Playlists preview
        echo '<div class="card"><h2>歌单</h2>';
        try {
            $stmt = $pdo->prepare('SELECT id, name, updated_at FROM music_user_playlists WHERE user_id=? ORDER BY updated_at DESC LIMIT 50');
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll();
            echo '<div style="overflow:auto"><table style="width:100%;border-collapse:collapse">';
            echo '<thead><tr>';
            echo '<th style="text-align:left;padding:8px;border-bottom:1px solid rgba(255,255,255,.10)">ID</th>';
            echo '<th style="text-align:left;padding:8px;border-bottom:1px solid rgba(255,255,255,.10)">名称</th>';
            echo '<th style="text-align:left;padding:8px;border-bottom:1px solid rgba(255,255,255,.10)">更新时间</th>';
            echo '<th style="text-align:left;padding:8px;border-bottom:1px solid rgba(255,255,255,.10)">曲目</th>';
            echo '</tr></thead><tbody>';
            foreach ($rows as $r) {
                $pid = (int)($r['id'] ?? 0);
                $name = (string)($r['name'] ?? '');
                $ua = (string)($r['updated_at'] ?? '');
                $count = 0;
                try {
                    $st2 = $pdo->prepare('SELECT COUNT(*) AS c FROM music_user_playlist_tracks WHERE playlist_id=?');
                    $st2->execute([$pid]);
                    $count = (int)($st2->fetch()['c'] ?? 0);
                } catch (Throwable $e) {
                    $count = 0;
                }
                echo '<tr>';
                echo '<td style="padding:8px;border-bottom:1px solid rgba(255,255,255,.06);color:#9fb2c8">' . $pid . '</td>';
                echo '<td style="padding:8px;border-bottom:1px solid rgba(255,255,255,.06)">' . h($name) . '</td>';
                echo '<td style="padding:8px;border-bottom:1px solid rgba(255,255,255,.06);color:#cfe2f6">' . h($ua) . '</td>';
                echo '<td style="padding:8px;border-bottom:1px solid rgba(255,255,255,.06)">' . $count . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        } catch (Throwable $e) {
            echo '<p class="bad">读取失败</p>';
        }
        echo '</div>';

        echo '</div>'; // row

        echo '</div>'; // card
        admin_layout_footer();
        exit;
    }

    // List view
    $summaryUsers = 0;
    $summaryFav = 0;
    $summaryRec = 0;
    $summaryPl = 0;
    try {
        $summaryUsers = (int)($pdo->query('SELECT COUNT(*) AS c FROM music_users')->fetch()['c'] ?? 0);
        $summaryFav = (int)($pdo->query('SELECT COUNT(*) AS c FROM music_user_favorites')->fetch()['c'] ?? 0);
        $summaryRec = (int)($pdo->query('SELECT COUNT(*) AS c FROM music_user_recents')->fetch()['c'] ?? 0);
        $summaryPl = (int)($pdo->query('SELECT COUNT(*) AS c FROM music_user_playlists')->fetch()['c'] ?? 0);
    } catch (Throwable $e) {
        // ignore
    }
    echo '<p class="mono">用户数: ' . $summaryUsers . "\n";
    echo '收藏总数: ' . $summaryFav . "\n";
    echo '最近总数: ' . $summaryRec . "\n";
    echo '歌单总数: ' . $summaryPl . "\n";
    echo '</p>';

    $limit = 50;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
    try {
        $stmt = $pdo->prepare('SELECT id, username, avatar_path, created_at FROM music_users ORDER BY id DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset);
        $stmt->execute();
        $users = $stmt->fetchAll();

        $today = gmdate('Y-m-d');
        $rows = [];
        foreach ($users as $u) {
            $id = (int)($u['id'] ?? 0);
            if ($id <= 0) continue;

            $fav = 0;
            $rec = 0;
            $pls = 0;
            $sec = 0;
            try {
                $st = $pdo->prepare('SELECT COUNT(*) AS c FROM music_user_favorites WHERE user_id=?');
                $st->execute([$id]);
                $fav = (int)($st->fetch()['c'] ?? 0);

                $st = $pdo->prepare('SELECT COUNT(*) AS c FROM music_user_recents WHERE user_id=?');
                $st->execute([$id]);
                $rec = (int)($st->fetch()['c'] ?? 0);

                $st = $pdo->prepare('SELECT COUNT(*) AS c FROM music_user_playlists WHERE user_id=?');
                $st->execute([$id]);
                $pls = (int)($st->fetch()['c'] ?? 0);

                $st = $pdo->prepare('SELECT seconds FROM music_listening_daily WHERE user_id=? AND day=? LIMIT 1');
                $st->execute([$id, $today]);
                $sec = (int)(($st->fetch()['seconds'] ?? 0));
            } catch (Throwable $e) {
                // ignore
            }

            $rows[] = [
                'id' => $id,
                'username' => (string)($u['username'] ?? ''),
                'avatar_path' => (string)($u['avatar_path'] ?? ''),
                'created_at' => (string)($u['created_at'] ?? ''),
                'favorites' => $fav,
                'recents' => $rec,
                'playlists' => $pls,
                'today_seconds' => $sec,
            ];
        }
        render_user_list($rows);

        echo '<div style="height:12px"></div>';
        echo '<div>'; 
        if ($offset > 0) {
            $prev = max(0, $offset - $limit);
            echo '<a class="btn secondary" href="/admin/data.php?tab=users&offset=' . $prev . '">上一页</a> ';
        }
        if (($offset + $limit) < $summaryUsers) {
            $next = $offset + $limit;
            echo '<a class="btn secondary" href="/admin/data.php?tab=users&offset=' . $next . '">下一页</a>';
        }
        echo '</div>';
    } catch (Throwable $e) {
        echo '<p class="bad">读取失败：' . h($e->getMessage()) . '</p>';
    }

    echo '</div>';
}

admin_layout_footer();
