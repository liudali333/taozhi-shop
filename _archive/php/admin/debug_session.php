<?php
/**
 * Session 诊断页 - 用于排查登录状态丢失问题
 * 访问: /admin/debug_session.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: text/plain; charset=utf-8');

echo "=== Session 诊断 ===\n\n";

echo "session_status: ";
switch (session_status()) {
    case PHP_SESSION_DISABLED: echo "PHP_SESSION_DISABLED\n"; break;
    case PHP_SESSION_NONE: echo "PHP_SESSION_NONE (未启动)\n"; break;
    case PHP_SESSION_ACTIVE: echo "PHP_SESSION_ACTIVE (已启动)\n"; break;
}

echo "\nsession_id: " . session_id() . "\n";
echo "session_name: " . session_name() . "\n";

echo "\n=== Session 数据 ===\n";
print_r($_SESSION);

echo "\n=== Cookie 信息 ===\n";
echo "cookie: " . session_name() . " = " . ($_COOKIE[session_name()] ?? '(未设置)') . "\n";

echo "\n=== PHP Session 配置 ===\n";
$keys = ['session.save_path', 'session.save_handler', 'session.cookie_domain',
         'session.cookie_path', 'session.cookie_lifetime', 'session.cookie_httponly',
         'session.cookie_samesite', 'session.use_strict_mode', 'session.gc_maxlifetime'];
foreach ($keys as $k) {
    echo "$k = " . (ini_get($k) ?: '(未设置)') . "\n";
}

echo "\n=== 路径测试 ===\n";
$testFile = DATA_DIR . 'session_test_' . time() . '.txt';
$result = @file_put_contents($testFile, 'test');
if ($result !== false) {
    echo "✅ DATA_DIR 可写: $testFile\n";
    @unlink($testFile);
} else {
    echo "❌ DATA_DIR 不可写: " . DATA_DIR . "\n";
}

echo "\n=== Session 目录 ===\n";
$ssPath = ini_get('session.save_path') ?: sys_get_temp_dir();
echo "session.save_path: $ssPath\n";
$testF = $ssPath . '/taozhi_test_' . time() . '.txt';
$r = @file_put_contents($testF, 'test');
if ($r !== false) {
    echo "✅ Session 目录可写\n";
    @unlink($testF);
} else {
    echo "❌ Session 目录不可写 (可能导致会话丢失)\n";
}

echo "\n=== 修复建议 ===\n";
echo "如果 session.save_path = /tmp 且不可写，在宝塔 PHP 设置中添加：\n";
echo "session.save_path = '/www/server/php/session'\n";
echo "并确保该目录存在且有写入权限。\n";
?>
