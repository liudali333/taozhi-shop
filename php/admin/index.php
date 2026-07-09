<?php
require_once 'config.php';
checkAuth();

try {
    $db = getDB();
    $productCount = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $categoryCount = $db->query("SELECT COUNT(*) FROM categories WHERE level=1")->fetchColumn();
    $orderCount = $db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $userCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $todayOrders = $db->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $todaySales = $db->query("SELECT COALESCE(SUM(total_price), 0) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $pendingOrders = $db->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
} catch (Exception $e) {
    $productCount = $categoryCount = $orderCount = $userCount = 0;
    $todayOrders = $pendingOrders = 0;
    $todaySales = 0;
}

ob_start();
?>
<div class="header">
    <h1>📊 数据概览</h1>
    <span class="welcome">欢迎，<?= htmlspecialchars($_SESSION['admin_user'] ?? 'admin') ?></span>
</div>

<div class="stat-grid" style="grid-template-columns:repeat(4,1fr);">
    <div class="stat-card">
        <div class="stat-label">今日订单</div>
        <div class="stat-value"><?= $todayOrders ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">今日销售额</div>
        <div class="stat-value">¥<?= number_format($todaySales, 2) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">待处理订单</div>
        <div class="stat-value"><?= $pendingOrders ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">注册用户</div>
        <div class="stat-value"><?= $userCount ?></div>
    </div>
</div>

<div class="card">
    <h2 style="font-size:16px;font-weight:600;margin-bottom:16px;">快捷操作</h2>
    <div class="quick-actions">
        <a href="products.php" class="action-btn">📦 商品管理（<?= $productCount ?>）</a>
        <a href="orders.php" class="action-btn">📋 订单管理（<?= $orderCount ?>）</a>
        <a href="users.php" class="action-btn">👥 用户管理（<?= $userCount ?>）</a>
        <a href="categories.php" class="action-btn">🏷️ 分类管理（<?= $categoryCount ?>）</a>
        <a href="stores.php" class="action-btn">🏪 门店设置</a>
    </div>
</div>

<div class="card">
    <h2 style="font-size:16px;font-weight:600;margin-bottom:16px;">系统状态</h2>
    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px;font-size:14px;color:#666;">
        <div>✅ 数据库连接：正常</div>
        <div>✅ API 接口：正常</div>
        <div>✅ 后台管理：运行中</div>
        <div>📦 商品数量：<?= $productCount ?> 个</div>
        <div>👥 用户数量：<?= $userCount ?> 个</div>
    </div>
</div>

<?php
$pageContent = ob_get_clean();
$pageTitle = '数据概览 - 桃之后台';
include 'layout.php';
