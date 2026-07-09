<?php
/**
 * checkAuth 诊断页 - 排查为什么会跳登录
 * 访问: https://taozhi.433345.xyz/admin/check_auth.php
 */
require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: text/plain; charset=utf-8');

echo "=== 当前状态 ===\n";
echo "session_id: " . session_id() . "\n";
echo "admin_logged_in: " . ($_SESSION['admin_logged_in'] ?? '未设置') . "\n";
echo "\n=== $_SERVER 信息 ===\n";
echo "PHP_SELF: " . ($_SERVER['PHP_SELF'] ?? '') . "\n";
echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? '') . "\n";
echo "HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? '') . "\n";
echo "SERVER_NAME: " . ($_SERVER['SERVER_NAME'] ?? '') . "\n";
echo "\n=== Cookie ===\n";
print_r($_COOKIE);

echo "\n=== 模拟 checkAuth 逻辑 ===\n";
$loggedIn = !empty($_SESSION['admin_logged_in']);
echo "logged_in = " . ($loggedIn ? 'true' : 'false') . "\n";
$page = basename($_SERVER['PHP_SELF'] ?? '');
echo "当前页面 = $page\n";

if (!$loggedIn) {
    echo "→ 应该跳转到: login.php\n";
    echo "→ 完整URL: https://{$_SERVER['HTTP_HOST']}/admin/login.php\n";
    
    // 测试手动跳转
    // header("Location: https://{$_SERVER['HTTP_HOST']}/admin/login.php");
    // exit;
} else {
    echo "→ 无需跳转，已登录\n";
}

echo "\n=== 测试: 写入 session 后刷新 ===\n";
$_SESSION['admin_test_nav'] = 'nav_' . time();
echo "已写入 test_nav = " . $_SESSION['admin_test_nav'] . "\n";
echo "刷新本页，如果 session_id 变了，说明服务器 session 传递有问题\n";
echo "如果 session_id 不变但 test_nav 消失，说明存到了不同路径\n";
?>
