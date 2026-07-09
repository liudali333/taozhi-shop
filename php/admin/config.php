<?php
/**
 * 桃之后台管理 - 配置文件
 */

// 后台账号密码
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'taozhi2026');

// 数据库配置（统一从 db_config.php 读取）
$dbCfg = require __DIR__ . '/../db_config.php';
define('DB_HOST', $dbCfg['host'] ?? 'localhost');
define('DB_NAME', $dbCfg['name'] ?? 'taozhi_db');
define('DB_USER', $dbCfg['user'] ?? 'root');
define('DB_PASS', $dbCfg['pass'] ?? '');

// 启动 Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// MySQL 连接
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . ($dbCfg['charset'] ?? 'utf8mb4'),
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

// 通用 JSON 响应
function response($code = 0, $msg = '', $data = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

// 鉴权检查
function checkAuth() {
    if (empty($_SESSION['admin_logged_in'])) {
        $page = basename($_SERVER['PHP_SELF'] ?? '');
        if ($page !== 'login.php') {
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'taozhi.433345.xyz';
            header('Location: ' . $proto . '://' . $host . '/admin/login.php');
            exit;
        }
    }
}

// 兼容旧函数
function readData($key) { return []; }
function writeData($key, $data) { return true; }
function response_json($code = 0, $msg = '', $data = []) {
    return response($code, $msg, $data);
}
