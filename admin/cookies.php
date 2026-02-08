<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

admin_require_auth();

$qqPath = __DIR__ . '/../qq/cookie';
$wyyPath = __DIR__ . '/../wyy/cookie';

$msg = '';
$err = '';

function read_file_safe(string $path): string {
    if (!is_file($path)) return '';
    $s = (string)file_get_contents($path);
    return trim($s);
}

function write_file_safe(string $path, string $content): void {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true)) {
            throw new RuntimeException('mkdir failed: ' . $dir);
        }
    }
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('write failed: ' . $path);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'save_text') {
            $qq = trim((string)($_POST['qq_cookie'] ?? ''));
            $wyy = trim((string)($_POST['wyy_cookie'] ?? ''));
            if ($qq === '' || $wyy === '') {
                throw new RuntimeException('Both cookies are required.');
            }
            write_file_safe($qqPath, $qq);
            write_file_safe($wyyPath, $wyy);
            $msg = '已保存。';
        } elseif ($action === 'upload') {
            if (!isset($_FILES['qq_file']) || !isset($_FILES['wyy_file'])) {
                throw new RuntimeException('Files missing.');
            }
            $qqTmp = (string)($_FILES['qq_file']['tmp_name'] ?? '');
            $wyyTmp = (string)($_FILES['wyy_file']['tmp_name'] ?? '');
            if ($qqTmp === '' || $wyyTmp === '') {
                throw new RuntimeException('Upload failed.');
            }
            $qq = trim((string)file_get_contents($qqTmp));
            $wyy = trim((string)file_get_contents($wyyTmp));
            if ($qq === '' || $wyy === '') {
                throw new RuntimeException('Empty cookie file.');
            }
            write_file_safe($qqPath, $qq);
            write_file_safe($wyyPath, $wyy);
            $msg = '已上传。';
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$qq = read_file_safe($qqPath);
$wyy = read_file_safe($wyyPath);

admin_layout_header('Cookie管理');
echo '<div class="topnav">';
echo '<div><span class="tag">/admin</span> <span class="tag">cookies</span></div>';
echo '<div class="navlinks">';
echo '<a class="btn secondary" href="/admin/index.php">后台首页</a>';
echo '<a class="btn secondary" href="/admin/jobs.php">更新任务</a>';
echo '<a class="btn secondary" href="/admin/data.php">数据查看</a>';
echo '<a class="btn danger" href="/admin/logout.php">退出</a>';
echo '</div>';
echo '</div>';

if ($msg !== '') {
    echo '<div class="card" style="border-color:rgba(98,210,111,.25)"><p class="ok">' . htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></div><div style="height:12px"></div>';
}
if ($err !== '') {
    echo '<div class="card" style="border-color:rgba(255,102,102,.35)"><p class="bad"><b>错误:</b> ' . htmlspecialchars($err, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></div><div style="height:12px"></div>';
}

echo '<div class="row">';

echo '<div class="card">';
echo '<h1>编辑 Cookie</h1>';
echo '<p>这些文件会被 QQ/WYY 的抓取和接口调用使用。</p>';
echo '<form method="post">';
echo '<input type="hidden" name="csrf" value="' . htmlspecialchars(admin_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
echo '<input type="hidden" name="action" value="save_text">';
echo '<label>qq/cookie</label>';
echo '<textarea name="qq_cookie" spellcheck="false">' . htmlspecialchars($qq, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</textarea>';
echo '<label>wyy/cookie</label>';
echo '<textarea name="wyy_cookie" spellcheck="false">' . htmlspecialchars($wyy, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</textarea>';
echo '<div style="height:10px"></div>';
echo '<button class="btn" type="submit">保存</button>';
echo '</form>';
echo '</div>';

echo '<div class="card">';
echo '<h1>上传 Cookie</h1>';
echo '<p>上传两个文本文件，每个文件内容是浏览器请求头里的 Cookie 原文。</p>';
echo '<form method="post" enctype="multipart/form-data">';
echo '<input type="hidden" name="csrf" value="' . htmlspecialchars(admin_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
echo '<input type="hidden" name="action" value="upload">';
echo '<label>QQ Cookie 文件</label>';
echo '<input type="file" name="qq_file" required>';
echo '<label>WYY Cookie 文件</label>';
echo '<input type="file" name="wyy_file" required>';
echo '<div style="height:10px"></div>';
echo '<button class="btn" type="submit">上传</button>';
echo '</form>';
echo '<div style="height:12px"></div>';
echo '<div class="mono">QQ path: ' . htmlspecialchars($qqPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
echo 'WYY path: ' . htmlspecialchars($wyyPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
echo '</div>';

echo '</div>';

admin_layout_footer();
