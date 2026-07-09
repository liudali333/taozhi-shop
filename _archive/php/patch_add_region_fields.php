<?php
/**
 * 数据库补丁：为 store_single 表添加省市区字段
 * 
 * 使用方法：
 * 1. 上传本文件到服务器 php/ 目录
 * 2. 浏览器访问 https://你的域名/php/patch_add_region_fields.php
 * 3. 看到"补丁安装成功"后，删除本文件
 */

require_once __DIR__ . '/admin/config.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>数据库补丁：添加省市区字段</h2>";

try {
    $db = getDB();
    
    // 检查字段是否已存在
    $cols = $db->query("SHOW COLUMNS FROM store_single")->fetchAll();
    $colNames = array_column($cols, 'Field');
    
    $patches = [];
    
    if (!in_array('province', $colNames)) {
        $patches[] = "ADD COLUMN province VARCHAR(20) DEFAULT '' COMMENT '省份'";
    }
    if (!in_array('city', $colNames)) {
        $patches[] = "ADD COLUMN city VARCHAR(20) DEFAULT '' COMMENT '城市'";
    }
    if (!in_array('district', $colNames)) {
        $patches[] = "ADD COLUMN district VARCHAR(20) DEFAULT '' COMMENT '区县'";
    }
    
    if (count($patches) === 0) {
        echo "<p style='color:green;'>✓ 字段已存在，无需修补</p>";
    } else {
        $sql = "ALTER TABLE store_single " . implode(', ', $patches);
        $db->exec($sql);
        echo "<p style='color:green;'>✓ 补丁安装成功！已添加字段：</p>";
        echo "<ul>";
        foreach ($patches as $p) {
            echo "<li>" . htmlspecialchars($p) . "</li>";
        }
        echo "</ul>";
        echo "<p style='color:red;'>⚠️ 请立即删除本文件（patch_add_region_fields.php），避免安全风险</p>";
    }
    
    // 显示当前表结构
    echo "<h3>当前表结构</h3>";
    $cols = $db->query("SHOW COLUMNS FROM store_single")->fetchAll();
    echo "<table border='1' cellpadding='8' cellspacing='0'>";
    echo "<tr><th>Field</th><th>Type</th><th>Comment</th></tr>";
    foreach ($cols as $c) {
        echo "<tr><td>{$c['Field']}</td><td>{$c['Type']}</td><td></td></tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p style='color:red;'>✗ 错误：" . htmlspecialchars($e->getMessage()) . "</p>";
}
