<?php
/**
 * 数据库配置文件
 * 桃之成人用品小程序
 */

// 数据库配置（示例，实际使用时替换为真实数据库信息）
define('DB_HOST', 'localhost');
define('DB_NAME', 'taozhi');
define('DB_USER', 'root');
define('DB_PASS', '');

/*
// 数据库连接示例代码（使用PDO）
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    // 数据库连接失败处理
    die(json_encode([
        'code' => 500,
        'msg' => '数据库连接失败',
        'data' => []
    ]));
}
*/
