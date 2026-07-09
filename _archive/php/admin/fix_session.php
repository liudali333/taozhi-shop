<?php
/**
 * Session 修复脚本 - 访问此文件即自动修复
 * URL: https://taozhi.433345.xyz/admin/fix_session.php
 * 修复后请删除此文件！
 */

header('Content-Type: text/plain; charset=utf-8');

echo "=== Session 修复脚本 ===\n\n";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. 测试当前 session 是否正常
echo "[1] 测试 Session 读写...\n";
$_SESSION['test_key'] = 'test_value_' . time();
$testVal = $_SESSION['test_key'] ?? null;
if ($testVal) {
    echo "✅ Session 写入正常: $testVal\n\n";
} else {
    echo "❌ Session 写入失败！检查 PHP 配置。\n\n";
}

// 2. 创建 session 目录
echo "[2] 创建 Session 专用目录...\n";
$sessionDir = '/www/server/php/session/taozhi';
if (!is_dir($sessionDir)) {
    if (@mkdir($sessionDir, 0755, true)) {
        echo "✅ 目录已创建: $sessionDir\n";
        @chmod($sessionDir, 0755);
    } else {
        echo "⚠️ 无法创建 $sessionDir，跳过\n";
        $sessionDir = sys_get_temp_dir();
        echo "    改用临时目录: $sessionDir\n";
    }
} else {
    echo "✅ 目录已存在: $sessionDir\n";
}

// 3. 写入测试
$testFile = $sessionDir . '/test_' . time() . '.tmp';
if (@file_put_contents($testFile, 'test') !== false) {
    @unlink($testFile);
    echo "✅ 目录可写\n\n";
} else {
    echo "❌ 目录不可写！需要手动设置权限: chmod 0755 $sessionDir\n\n";
}

// 4. 写入 .user.ini（在当前 admin 目录）
echo "[3] 写入 .user.ini（Session 配置）...\n";
$userIni = __DIR__ . '/.user.ini';
$content = "session.save_path = \"$sessionDir\"\nsession.cookie_path = /\n";
if (@file_put_contents($userIni, $content) !== false) {
    @chmod($userIni, 0644);
    echo "✅ .user.ini 已写入: $userIni\n";
    echo "   内容:\n" . str_repeat(' ', 4) . implode("\n" . str_repeat(' ', 4), array_filter(explode("\n", $content))) . "\n\n";
} else {
    echo "⚠️ .user.ini 写入失败（权限问题），请手动创建:\n";
    echo "   文件: $userIni\n";
    echo "   内容: session.save_path = \"$sessionDir\"\n\n";
}

// 5. 创建 .htaccess（Nginx/Apache 兼容）
echo "[4] 写入 .htaccess...\n";
$htaccess = __DIR__ . '/.htaccess';
$htContent = "<IfModule mod_php.c>\n    php_value session.save_path \"$sessionDir\"\n    php_value session.cookie_path \"/\"\n</IfModule>\n";
if (@file_put_contents($htaccess, $htContent) !== false) {
    @chmod($htaccess, 0644);
    echo "✅ .htaccess 已写入\n\n";
} else {
    echo "⚠️ .htaccess 写入失败（权限问题）\n\n";
}

echo "=== 修复完成 ===\n";
echo "\n请执行以下步骤:\n";
echo "1. 清理浏览器缓存（Ctrl+Shift+R）\n";
echo "2. 重新访问 https://taozhi.433345.xyz/admin/\n";
echo "3. 重新登录（账号: admin / taozhi2026）\n";
echo "\n如果仍有问题，检查宝塔面板 → PHP设置 → 配置文件:\n";
echo "session.save_path = \"$sessionDir\"\n";
echo "\n⚠️ 修复成功后请删除本文件！\n";
?>
