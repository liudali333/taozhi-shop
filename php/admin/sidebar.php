<?php
// 获取当前页面文件名用于高亮
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!-- 侧边栏 -->
<aside class="sidebar">
    <div class="logo">🍑 桃之后台</div>
    <nav class="nav">
        <a href="index.php" class="nav-item <?= $currentPage === 'index.php' ? 'active' : '' ?>">📊 数据概览</a>
        <a href="products.php" class="nav-item <?= $currentPage === 'products.php' ? 'active' : '' ?>">📦 商品管理</a>
        <a href="categories.php" class="nav-item <?= $currentPage === 'categories.php' ? 'active' : '' ?>">🏷️ 分类管理</a>
        <a href="users.php" class="nav-item <?= $currentPage === 'users.php' ? 'active' : '' ?>">👥 用户管理</a>
        <a href="orders.php" class="nav-item <?= $currentPage === 'orders.php' ? 'active' : '' ?>">📋 订单管理</a>
        <a href="stores.php" class="nav-item <?= $currentPage === 'stores.php' ? 'active' : '' ?>">🏪 门店管理</a>
        <a href="banners.php" class="nav-item <?= $currentPage === 'banners.php' ? 'active' : '' ?>">🖼️ 轮播图管理</a>
        <a href="share.php" class="nav-item <?= $currentPage === 'share.php' ? 'active' : '' ?>">📤 分享图管理</a>
        <a href="coupons.php" class="nav-item <?= $currentPage === 'coupons.php' ? 'active' : '' ?>">🎫 优惠券管理</a>
        <a href="logout.php" class="nav-item">🚪 退出登录</a>
    </nav>
</aside>
