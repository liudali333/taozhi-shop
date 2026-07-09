<?php
/**
 * 数据库结构诊断脚本 - 检查所有表的多余字段
 * 访问后删除此文件
 */
$cfg = require __DIR__ . '/../db_config.php';

// 移除运行时警告
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

$dsn = "mysql:host={$cfg['host']};charset=utf8";
try {
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass']);
    $pdo->exec("USE `{$cfg['name']}`");
} catch (Exception $e) {
    die('连接失败: ' . $e->getMessage());
}

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

echo "<html><head><meta charset='utf-8'><title>数据库诊断</title>";
echo "<style>body{font-family:monospace;font-size:13px;padding:20px;background:#f5f5f5;}";
echo "h2{color:#333;border-bottom:2px solid #4a9eff;padding-bottom:5px;}";
echo "table{border-collapse:collapse;width:100%;margin-bottom:30px;background:#fff;}";
echo "th,td{border:1px solid #ddd;padding:6px 10px;text-align:left;font-size:12px;}";
echo "th{background:#4a9eff;color:#fff;}";
echo ".extra{background:#fff3cd;color:#856404;}"; // 黄色 = 可疑
echo ".red{color:#e64340;font-weight:bold;}"; // 红色 = 建议删除
echo "tr:nth-child(even){background:#f9f9f9;}";
echo "summary{cursor:pointer;background:#e8f4ff;padding:8px;font-weight:bold;}";
echo ".tip{background:#d4edda;color:#155724;padding:10px;border-radius:6px;margin-bottom:20px;}";
echo "</style></head><body>";

echo "<h1>📊 数据库结构诊断 — taozhi</h1>";
echo "<div class='tip'>🔍 分析完成。标 <span class='red'>红色</span> 字段为建议删除的多余字段。</div>";

// 规则：哪些字段是"可能多余"的（根据项目历史）
$known_legacy = [
    // 全局：__dbs_has_role 等会话调试字段
    '__dbs_has_role', '__debug', '__mock_user_id', 'mock_openid',
    // products 相关
    'tags', 'spec', 'original_price', 'cost_price', 'barcode',
    'category_id', 'category_name',
    // 废弃的 address 字段
    'tag', 'realname', 'gender',
    // users 表旧字段
    'phone', 'nickname', 'avatar_url', 'session_key',
    // banners 表旧字段
    'type', 'url',
    // orders 废弃字段
    'total_amount', 'original_price', 'discount_amount',
    'rider_status', 'estimated_time',
    // stores 废弃字段
    'delivery_radius', 'description', 'delivery_fee_old',
];

foreach ($tables as $table) {
    $cols = $pdo->query("SHOW FULL COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_NUM);
    $count = count($cols);
    
    // 检查"可疑"字段（数据库有但代码从未写入）
    $suspicious = [];
    $legacy = [];
    foreach ($cols as $col) {
        $name = $col[0];
        $type = $col[1];
        $null = $col[3];
        $def = $col[5];
        
        // 标记所有在已知废弃列表中的字段
        if (in_array($name, $known_legacy)) {
            $legacy[] = [$name, $type, $null, $def, '已知废弃字段'];
        }
        // 标记 nullable + 默认 NULL + 无意义名称的字段（如 xxx_id_old）
        if ($null === 'YES' && ($def === 'NULL' || $def === '') 
            && (strpos($name, '_old') !== false || strpos($name, 'unused') !== false || strpos($name, 'temp_') !== false)) {
            $suspicious[] = [$name, $type, $null, $def, '疑似临时/废弃字段'];
        }
    }
    
    $issues = array_merge($legacy, $suspicious);
    $bg = count($issues) > 0 ? '#fff' : '#fafafa';
    
    echo "<h2>📋 {$table} <span style='font-weight:normal;color:#888;font-size:14px;'>({$count}字段)</span></h2>";
    echo "<table style='background:{$bg}'>";
    echo "<tr><th>字段名</th><th>类型</th><th>NULL</th><th>默认值</th><th>备注</th></tr>";
    foreach ($cols as $col) {
        $name = $col[0];
        $type = $col[1];
        $null = $col[3];
        $def = $col[5];
        $extra = $col[6] ?? '';
        
        $isLegacy = false;
        $note = '';
        foreach ($issues as $iss) {
            if ($iss[0] === $name) {
                $isLegacy = true;
                $note = "<span class='red'>⚠ {$iss[4]}</span>";
                break;
            }
        }
        
        $rowStyle = $isLegacy ? 'background:#fff3cd;' : '';
        $nameDisp = $isLegacy ? "<span class='red'>{$name}</span>" : $name;
        echo "<tr style='{$rowStyle}'><td>{$nameDisp}</td><td>{$type}</td><td>{$null}</td><td>{$def}</td><td>{$note} {$extra}</td></tr>";
    }
    echo "</table>";
}

echo "<hr><h2>🧹 建议清理操作（SQL）</h2>";
echo "<pre style='background:#1e1e1e;color:#d4d4d4;padding:15px;border-radius:8px;font-size:12px;'>";
$cleanup_sql = [];

// 按项目历史积累的废弃字段（手动列举）
$cleanup_sql[] = "-- 清理 banners 表多余字段（url/type字段实际未用）";
$cleanup_sql[] = "-- ALTER TABLE banners DROP COLUMN IF EXISTS url;";
$cleanup_sql[] = "-- ALTER TABLE banners DROP COLUMN IF EXISTS type;";
$cleanup_sql[] = "";
$cleanup_sql[] = "-- 清理 users 表可疑调试字段";
$cleanup_sql[] = "-- ALTER TABLE users DROP COLUMN IF EXISTS session_key;";
$cleanup_sql[] = "-- ALTER TABLE users DROP COLUMN IF EXISTS __dbs_has_role;";
$cleanup_sql[] = "-- ALTER TABLE users DROP COLUMN IF EXISTS __debug;";
$cleanup_sql[] = "-- ALTER TABLE users DROP COLUMN IF EXISTS __mock_user_id;";
$cleanup_sql[] = "-- ALTER TABLE users DROP COLUMN IF EXISTS mock_openid;";
$cleanup_sql[] = "";
$cleanup_sql[] = "-- 清理 products 表废弃字段";
$cleanup_sql[] = "-- ALTER TABLE products DROP COLUMN IF EXISTS original_price;";
$cleanup_sql[] = "-- ALTER TABLE products DROP COLUMN IF EXISTS cost_price;";
$cleanup_sql[] = "-- ALTER TABLE products DROP COLUMN IF EXISTS barcode;";
$cleanup_sql[] = "-- ALTER TABLE products DROP COLUMN IF EXISTS tags;";
$cleanup_sql[] = "-- ALTER TABLE products DROP COLUMN IF EXISTS category_name;";
$cleanup_sql[] = "";
$cleanup_sql[] = "-- 清理 orders 表可疑调试字段";
$cleanup_sql[] = "-- ALTER TABLE orders DROP COLUMN IF EXISTS __dbs_has_role;";
$cleanup_sql[] = "-- ALTER TABLE orders DROP COLUMN IF EXISTS __mock_user_id;";
$cleanup_sql[] = "";
$cleanup_sql[] = "-- 清理 stores 表旧字段（已迁移到 delivery_zones）";
$cleanup_sql[] = "-- ALTER TABLE stores DROP COLUMN IF EXISTS delivery_radius;";
$cleanup_sql[] = "-- ALTER TABLE stores DROP COLUMN IF EXISTS description;";

echo implode("\n", $cleanup_sql);
echo "</pre>";
echo "<p style='color:#999;font-size:12px;'>⚠️ 以上 SQL 均为注释状态，确认无误后取消注释执行。执行前请备份数据库！</p>";
echo "</body></html>";
