<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

admin_require_auth();

admin_layout_header('后台管理');
echo '<div class="topnav">';
echo '<div><span class="tag">/admin</span> <span class="tag">dashboard</span></div>';
echo '<div class="navlinks">';
echo '<a class="btn secondary" href="/admin/cookies.php">Cookie管理</a>';
echo '<a class="btn secondary" href="/admin/jobs.php">更新任务</a>';
echo '<a class="btn secondary" href="/admin/data.php">数据查看</a>';
echo '<a class="btn secondary" href="/admin/version.php">版本管理</a>';
echo '<a class="btn secondary" href="/admin/status.php?name=ingest_charts">任务状态</a>';
echo '<a class="btn danger" href="/admin/logout.php">退出</a>';
echo '</div>';
echo '</div>';

echo '<div class="row">';

echo '<div class="card">';
echo '<h1>快捷入口</h1>';
echo '<p><a href="/admin/cookies.php">更新 QQ/WYY Cookie</a></p>';
echo '<p><a href="/admin/jobs.php">更新榜单/首页缓存</a></p>';
echo '<p><a href="/admin/data.php">查看当前榜单/QQ首页数据</a></p>';
echo '<p class="mono">Root: ' . htmlspecialchars(__DIR__ . '/..', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
echo 'Python服务: ' . htmlspecialchars(admin_cfg('PY_BASE_URL', DEFAULT_PY_BASE_URL), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
echo 'Node服务: ' . htmlspecialchars(admin_cfg('NODE_BASE_URL', 'http://127.0.0.1:3000'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
echo 'Redis: ' . htmlspecialchars(admin_cfg('REDIS_HOST', '127.0.0.1'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ':' . htmlspecialchars(admin_cfg('REDIS_PORT', '6379'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
echo '</p>';
echo '</div>';

echo '<div class="card">';
echo '<h1>安全</h1>';
echo '<p>强烈建议: 在宝塔里给 /admin 做 IP 限制或 BasicAuth。</p>';
echo '<p>当前白名单: <span class="mono">' . htmlspecialchars(admin_cfg('ADMIN_IP_ALLOWLIST', ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span></p>';
echo '</div>';

echo '</div>';

admin_layout_footer();
