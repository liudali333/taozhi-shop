<?php
/**
 * 数据库连接 - 读取统一配置
 */
$cfg = require __DIR__ . '/../db_config.php';

if (!function_exists('getDB')) {
    function getDB() {
        global $cfg;
        static $pdo = null;
        if ($pdo === null) {
            $dsn = 'mysql:host=' . $cfg['host'] . ';dbname=' . $cfg['name'] . ';charset=' . ($cfg['charset'] ?? 'utf8mb4');
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
        return $pdo;
    }
}
