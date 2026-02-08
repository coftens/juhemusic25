<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

admin_require_installed();

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $pass = (string)($_POST['password'] ?? '');
    $hash = admin_password_hash();
    if ($hash === '' || !password_verify($pass, $hash)) {
        $err = 'Invalid password.';
    } else {
        $_SESSION['admin_authed'] = true;
        header('Location: /admin/index.php');
        exit;
    }
}

admin_layout_header('后台登录');
echo '<div class="topnav"><div><span class="tag">/admin</span> <span class="tag">login</span></div></div>';

if ($err !== '') {
    echo '<div class="card" style="border-color:rgba(255,102,102,.35)"><p class="bad"><b>错误:</b> ' . htmlspecialchars($err, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></div><div style="height:12px"></div>';
}

echo '<div class="card">';
echo '<h1>登录</h1>';
echo '<form method="post">';
echo '<input type="hidden" name="csrf" value="' . htmlspecialchars(admin_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
echo '<label>密码</label>';
echo '<input name="password" type="password" required autofocus>';
echo '<div style="height:12px"></div>';
echo '<button class="btn" type="submit">登录</button>';
echo ' <a class="btn secondary" href="/admin/install.php" style="margin-left:8px">安装</a>';
echo '</form>';
echo '</div>';

admin_layout_footer();
