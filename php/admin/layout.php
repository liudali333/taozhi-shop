<?php
// 统一后台布局框架
// 用法: 在页面中设置 $pageTitle 和 $pageContent，然后 include 'layout.php'
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? '桃之后台') ?></title>
    <!-- 样式已内嵌 -->
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; }
        .layout { display: flex; min-height: 100vh; }
        
        /* 侧边栏 */
        .sidebar { width: 220px; background: #1a1a2e; color: #fff; position: fixed; top: 0; left: 0; height: 100vh; overflow-y: auto; z-index: 100; transition: transform 0.25s ease; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 99; }
        .menu-toggle { display: none; position: fixed; top: 12px; left: 12px; width: 40px; height: 40px; border: none; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.15); border-radius: 10px; font-size: 20px; cursor: pointer; align-items: center; justify-content: center; z-index: 102; }
        .logo { padding: 24px 20px; font-size: 18px; font-weight: 700; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav { padding: 12px 0; }
        .nav-item { display: block; padding: 14px 20px; color: rgba(255,255,255,0.7); text-decoration: none; font-size: 14px; transition: all 0.2s; border-left: 3px solid transparent; }
        .nav-item:hover { background: rgba(255,255,255,0.05); color: #fff; }
        .nav-item.active { background: rgba(230,67,64,0.15); color: #e64340; border-left-color: #e64340; }
        
        /* 主内容 */
        .main { flex: 1; margin-left: 220px; padding: 24px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .header h1 { font-size: 24px; font-weight: 700; color: #1a1a1a; }
        .welcome { color: #666; font-size: 14px; }
        
        /* 卡片 */
        .card { background: #fff; border-radius: 12px; padding: 24px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        
        /* 按钮 */
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px; border-radius: 8px; border: none; font-size: 14px; cursor: pointer; transition: all 0.2s; text-decoration: none; }
        .btn-primary { background: #e64340; color: #fff; }
        .btn-primary:hover { background: #d92b28; }
        .btn-secondary { background: #f5f5f5; color: #333; }
        .btn-danger { background: #fff0f0; color: #e64340; }
        .btn-sm { padding: 6px 14px; font-size: 13px; }
        
        /* 表格 */
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px 16px; font-size: 13px; color: #888; font-weight: 500; border-bottom: 2px solid #f0f0f0; white-space: nowrap; }
        td { padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #f5f5f5; }
        tr:hover td { background: #fafafa; }
        
        /* 表单 */
        .form-row { margin-bottom: 16px; }
        .form-label { display: block; font-size: 13px; color: #666; margin-bottom: 6px; font-weight: 500; }
        .form-label span { color: #e64340; }
        .form-input, .form-select, .form-textarea { width: 100%; padding: 10px 14px; border: 1.5px solid #e8e8e8; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s; }
        .form-input:focus, .form-select:focus { border-color: #e64340; }
        .form-textarea { resize: vertical; min-height: 80px; }
        .form-row-inline { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-row-inline-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-row-inline-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
        
        /* 分页 */
        .pagination { display: flex; gap: 4px; justify-content: center; margin-top: 20px; }
        .pagination a, .pagination span { display: inline-flex; padding: 8px 14px; border-radius: 6px; font-size: 13px; text-decoration: none; color: #666; }
        .pagination a:hover { background: #f0f0f0; }
        .pagination .current { background: #e64340; color: #fff; }
        .pagination .disabled { color: #ccc; pointer-events: none; }
        
        /* 消息 */
        .msg-box { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .msg-success { background: #f0f9f0; color: #2e7d32; border: 1px solid #c8e6c9; }
        .msg-error { background: #fff0f0; color: #c62828; border: 1px solid #ffcdd2; }
        
        /* 模态框 */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal-overlay.active { display: flex; }
        .modal { background: #fff; border-radius: 16px; padding: 28px; width: 520px; max-width: 95vw; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-title { font-size: 18px; font-weight: 700; }
        .modal-close { width: 32px; height: 32px; border-radius: 50%; background: #f5f5f5; border: none; cursor: pointer; font-size: 20px; }
        .form-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 20px; padding-top: 16px; border-top: 1px solid #f0f0f0; }
        
        /* 统计卡片 */
        .stat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .stat-label { font-size: 13px; color: #888; margin-bottom: 8px; }
        .stat-value { font-size: 28px; font-weight: 700; color: #1a1a1a; }
        .stat-card:nth-child(1) .stat-value { color: #e64340; }
        .stat-card:nth-child(2) .stat-value { color: #2196f3; }
        .stat-card:nth-child(3) .stat-value { color: #4caf50; }
        
        /* 标签 */
        .tag { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        .tag-on { background: #e8f5e9; color: #2e7d32; }
        .tag-off { background: #f5f5f5; color: #999; }
        .tag-pending { background: #fff3e0; color: #f57c00; }
        .tag-paid { background: #e3f2fd; color: #1976d2; }
        .tag-completed { background: #e8f5e9; color: #388e3c; }
        .tag-cancelled { background: #f5f5f5; color: #999; }
        
        /* 快捷操作 */
        .quick-actions { display: flex; gap: 12px; flex-wrap: wrap; }
        .action-btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 20px; background: #f5f5f5; border-radius: 8px; color: #333; text-decoration: none; font-size: 14px; transition: all 0.2s; }
        .action-btn:hover { background: #e8e8e8; }
        
        /* 订单 */
        .order-item { border: 1px solid #f0f0f0; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
        .order-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .order-no { font-weight: 600; font-size: 14px; color: #333; }
        .order-time { font-size: 12px; color: #999; }
        .order-items { background: #fafafa; border-radius: 8px; padding: 12px; margin-bottom: 12px; font-size: 13px; color: #555; }
        .order-footer { display: flex; justify-content: space-between; align-items: center; }
        .order-price { font-size: 18px; color: #e64340; font-weight: 700; }
        
        /* 树形分类 */
        .tree-item { margin-bottom: 12px; }
        .tree-l1 { display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: #fff5f5; border-radius: 10px; border-left: 4px solid #e64340; }
        .tree-l1-name { font-weight: 600; color: #333; font-size: 15px; flex: 1; }
        .tree-l2-wrap { padding-left: 20px; display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
        .tree-l2 { display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; background: #f5f5f5; border-radius: 6px; font-size: 13px; color: #555; }
        
        /* 优惠券 */
        .coupon-card { border: 1px solid #f0f0f0; border-radius: 10px; padding: 16px; margin-bottom: 12px; display: flex; align-items: center; gap: 16px; }
        .coupon-value { font-size: 28px; font-weight: 700; color: #e64340; min-width: 80px; }
        .coupon-info { flex: 1; }
        .coupon-title { font-weight: 600; color: #333; margin-bottom: 4px; }
        .coupon-desc { font-size: 12px; color: #999; }
        
        /* 响应式 */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); width: 200px; box-shadow: 2px 0 12px rgba(0,0,0,0.3); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main { margin-left: 0; padding: 16px; }
            .menu-toggle { display: inline-flex; }
            .header { margin-bottom: 16px; padding-left: 48px; }
            .header h1 { font-size: 20px; }
            .welcome { display: none; }
            .stat-grid { grid-template-columns: 1fr; }
            .form-row-inline, .form-row-inline-2, .form-row-inline-3 { grid-template-columns: 1fr; }
            .order-footer-row { flex-direction: column; align-items: flex-start; gap: 8px; }
            .date-filter { flex-direction: column; align-items: stretch; gap: 10px; }
            .date-filter form { flex-wrap: wrap; }
            table { display: block; overflow-x: auto; white-space: nowrap; }
        }
    </style>
</head>
<body>
    <div class="layout">
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
        <?php include 'sidebar.php'; ?>
        <main class="main">
            <button class="menu-toggle" onclick="openSidebar()" aria-label="菜单">☰</button>
            <?= $pageContent ?? '' ?>
        </main>
    </div>
    <script>
        function openSidebar() {
            document.querySelector('.sidebar').classList.add('open');
            document.getElementById('sidebarOverlay').classList.add('show');
        }
        function closeSidebar() {
            document.querySelector('.sidebar').classList.remove('open');
            document.getElementById('sidebarOverlay').classList.remove('show');
        }
    </script>
</body>
</html>
