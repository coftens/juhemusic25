<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

admin_require_auth();

$configFile = __DIR__ . '/version_config.json';

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $version = trim($_POST['version'] ?? '');
    $downloadUrl = trim($_POST['download_url'] ?? '');
    $force = isset($_POST['force']) && $_POST['force'] === '1';
    $changelog = trim($_POST['changelog'] ?? '');

    $data = [
        'version' => $version,
        'download_url' => $downloadUrl,
        'force' => $force,
        'changelog' => $changelog,
    ];

    file_put_contents($configFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $message = '版本信息已保存！';
}

// 读取当前配置
$config = [
    'version' => '',
    'download_url' => '',
    'force' => false,
    'changelog' => '',
];
if (file_exists($configFile)) {
    $config = json_decode(file_get_contents($configFile), true) ?: $config;
}

admin_layout_header('版本管理');
?>

<div class="topnav">
    <div><span class="tag">/admin</span> <span class="tag">version</span></div>
    <div class="navlinks">
        <a class="btn secondary" href="/admin/">首页</a>
        <a class="btn secondary" href="/admin/cookies.php">Cookie管理</a>
        <a class="btn secondary" href="/admin/jobs.php">更新任务</a>
        <a class="btn secondary" href="/admin/data.php">数据查看</a>
        <a class="btn danger" href="/admin/logout.php">退出</a>
    </div>
</div>

<div class="row">
    <div class="card" style="max-width: 600px;">
        <h1>📱 App 版本管理</h1>

        <?php if (!empty($message)): ?>
            <div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                ✅
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form method="POST" style="display: flex; flex-direction: column; gap: 16px;">
            <div>
                <label style="display: block; margin-bottom: 6px; font-weight: 600;">最新版本号:</label>
                <input type="text" name="version"
                    value="<?php echo htmlspecialchars($config['version'], ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="例如: 7.06"
                    style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 16px;">
            </div>

            <div>
                <label style="display: block; margin-bottom: 6px; font-weight: 600;">下载链接:</label>
                <input type="url" name="download_url"
                    value="<?php echo htmlspecialchars($config['download_url'], ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="https://example.com/app.apk"
                    style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 16px;">
            </div>

            <div>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" name="force" value="1" <?php echo $config['force'] ? 'checked' : ''; ?>
                    style="width: 18px; height: 18px;">
                    <span style="font-weight: 600;">强制更新</span>
                    <span style="color: #666; font-size: 14px;">(用户无法跳过更新弹窗)</span>
                </label>
            </div>

            <div>
                <label style="display: block; margin-bottom: 6px; font-weight: 600;">更新日志:</label>
                <textarea name="changelog" rows="5" placeholder="- 新增功能X&#10;- 修复问题Y&#10;- 优化体验Z"
                    style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; font-family: inherit; resize: vertical;"><?php echo htmlspecialchars($config['changelog'], ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <button type="submit" class="btn primary" style="padding: 12px 24px; font-size: 16px;">
                💾 保存版本信息
            </button>
        </form>
    </div>

    <div class="card" style="max-width: 400px;">
        <h1>📖 使用说明</h1>
        <p><strong>工作原理:</strong></p>
        <ul style="line-height: 1.8;">
            <li>App启动时会请求 <code>/api/version.php</code></li>
            <li>如果App版本 < 后台设置版本，弹出更新提示</li>
            <li>用户点击"立即更新"会跳转浏览器下载</li>
        </ul>
        <p style="margin-top: 16px;"><strong>发版流程:</strong></p>
        <ol style="line-height: 1.8;">
            <li>打包新版APK</li>
            <li>上传APK到下载服务器</li>
            <li>在此页面更新版本号和链接</li>
            <li>用户打开App即可看到更新提示</li>
        </ol>
    </div>
</div>

<?php admin_layout_footer(); ?>