<?php
/**
 * 清理数据库中所有 Oil 图床的 URL
 * 将 hosted_cover_url 中包含 oilgasgpts.com 的记录设置为 NULL
 */

require_once __DIR__ . '/php_api_common.php';

echo "=== 开始清理 Oil 图床 URL ===\n\n";

try {
    $pdo = pdo_connect_from_env();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 三个需要清理的表
    $tables = [
        'music_song_cache',
        'music_charts',
        'music_home_items'
    ];
    
    $total_cleaned = 0;
    
    foreach ($tables as $table) {
        echo "处理表: $table\n";
        
        // 先统计需要清理的记录数
        $count_sql = "SELECT COUNT(*) FROM $table WHERE hosted_cover_url LIKE '%oilgasgpts.com%'";
        $count_stmt = $pdo->query($count_sql);
        $count = $count_stmt->fetchColumn();
        
        echo "  发现 $count 条 Oil URL 记录\n";
        
        if ($count > 0) {
            // 执行清理
            $update_sql = "UPDATE $table SET hosted_cover_url = NULL WHERE hosted_cover_url LIKE '%oilgasgpts.com%'";
            $affected = $pdo->exec($update_sql);
            echo "  ✓ 已清理 $affected 条记录\n";
            $total_cleaned += $affected;
        } else {
            echo "  - 无需清理\n";
        }
        
        echo "\n";
    }
    
    echo "=== 清理完成 ===\n";
    echo "总计清理: $total_cleaned 条记录\n";
    echo "\n这些记录的封面将回退到使用 original_cover_url（源站地址）\n";
    echo "后续访问时会自动重新上传到 Jike 图床\n";
    
} catch (PDOException $e) {
    echo "❌ 数据库错误: " . $e->getMessage() . "\n";
    exit(1);
}
