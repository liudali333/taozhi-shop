<?php
require_once 'config.php';
checkAuth();

// 默认值
$filterDate = date('Y-m-d');
$orders = [];
$todayTotal = 0;
$todayIndex = [];
$totalPages = 1;
$page = 1;
$pageSize = 30;
$msg = '';

try {
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action === 'complete_pickup') {
            $stmt = $db->prepare("UPDATE orders SET status = 'completed' WHERE id = ? AND delivery_type != 'delivery' AND status = 'paid'");
            $stmt->execute([intval($_POST['id'])]);
            if ($stmt->rowCount() > 0) {
                header("Location: orders.php?date=" . urlencode($_POST['date'] ?? date('Y-m-d')) . "&msg=自提完成 ✅");
            } else {
                header("Location: orders.php?date=" . urlencode($_POST['date'] ?? date('Y-m-d')) . "&msg=操作失败：订单状态或类型不匹配");
            }
            exit;
        }
        // ===== 发配送 =====
        if ($action === 'dispatch') {
            $orderId = intval($_POST['id']);
            $orderNo = trim($_POST['order_no'] ?? '');
            if (!$orderNo) {
                header("Location: orders.php?date=" . urlencode($_POST['date'] ?? date('Y-m-d')) . "&msg=发配送失败：缺少订单号");
                exit;
            }
            $stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                header("Location: orders.php?date=" . urlencode($_POST['date'] ?? date('Y-m-d')) . "&msg=发配送失败：订单不存在");
                exit;
            }
            if ($order['status'] !== 'paid') {
                header("Location: orders.php?date=" . urlencode($_POST['date'] ?? date('Y-m-d')) . "&msg=发配送失败：订单不是已付款状态");
                exit;
            }
            if ($order['delivery_type'] !== 'delivery') {
                header("Location: orders.php?date=" . urlencode($_POST['date'] ?? date('Y-m-d')) . "&msg=发配送失败：这不是配送订单");
                exit;
            }
            if (strpos($order['remark'] ?? '', '[配送员:') !== false) {
                header("Location: orders.php?date=" . urlencode($_POST['date'] ?? date('Y-m-d')) . "&msg=该订单已发配送，请勿重复操作");
                exit;
            }
            // 发配送：delivery_status 进入5步流程第一阶段
            $stmt = $db->prepare("
                UPDATE orders SET
                    rider_name = NULL, rider_phone = NULL,
                    rider_lat = NULL, rider_lng = NULL,
                    delivery_status = 'accepted'
                WHERE id = ?
            ");
            $stmt->execute([$orderId]);
            header("Location: orders.php?date=" . urlencode($_POST['date'] ?? date('Y-m-d')) . "&msg=已接单 ✅ 正在召唤骑手");
            exit;
        }
    }

    if (!empty($_GET['msg'])) $msg = $_GET['msg'];

    // 日期筛选
    $filterDate = $_GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) $filterDate = date('Y-m-d');

    $page = max(1, intval($_GET['page'] ?? 1));
    $pageSize = 30;

    // 查询当天所有订单
    $allToday = $db->prepare("SELECT id, order_no FROM orders WHERE DATE(created_at) = ? ORDER BY created_at ASC");
    $allToday->execute([$filterDate]);
    $allTodayOrders = $allToday->fetchAll();
    $todayTotal = count($allTodayOrders);

    // 建立 id => 当天序号
    $todayIndex = [];
    foreach ($allTodayOrders as $idx => $o) {
        $todayIndex[$o['id']] = $idx + 1;
    }

    $totalPages = max(1, ceil($todayTotal / $pageSize));
    $offset = ($page - 1) * $pageSize;
    $pageIds = array_slice(array_column($allTodayOrders, 'id'), $offset, $pageSize);

    if (!empty($pageIds)) {
        $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
        $stmt = $db->prepare("SELECT * FROM orders WHERE id IN ($placeholders) ORDER BY created_at ASC");
        $stmt->execute($pageIds);
        $orders = $stmt->fetchAll();
    } else {
        $orders = [];
    }

    $statusMap = [
        'pending'    => ['待付款',   '#ff9800', '#fff3e0'],
        'paid'       => ['已付款',   '#2196f3', '#e3f2fd'],
        'delivering' => ['配送中',   '#00bcd4', '#e0f7fa'],
        'completed'  => ['已完成',  '#4caf50', '#e8f5e9'],
        'cancelled'  => ['已取消',  '#999',    '#f5f5f5'],
    ];

} catch (Exception $e) {
    $msg = '数据库错误：' . $e->getMessage();
    error_log('[orders.php] Exception: ' . $e->getMessage());
}

ob_start();
?>
<style>
.order-card { background:#fff; border-radius:12px; margin-bottom:16px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
.order-serial { padding:12px 20px; background:#fff3e0; border-bottom:1px solid #ffe0b2; display:flex; justify-content:space-between; align-items:center; }
.order-serial .serial-num { font-weight:700; font-size:16px; color:#e65100; font-family:monospace; }
.order-serial .serial-time { font-size:12px; color:#999; }
.order-body { padding:16px 20px; }
.info-row { display:flex; gap:8px; align-items:flex-start; margin-bottom:10px; }
.info-label { font-size:12px; color:#999; min-width:48px; padding-top:2px; }
.info-value { font-size:14px; color:#333; flex:1; }
.tag-status { display:inline-block; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:600; }
.remark-val { font-size:13px; color:#888; font-style:italic; }
.divider { height:1px; background:#f0f0f0; margin:12px 0; }
.products-toggle { display:flex; justify-content:space-between; align-items:center; padding:10px 0; cursor:pointer; font-size:13px; color:#666; }
.products-toggle .arrow { transition:transform 0.2s; }
.products-toggle .arrow.open { transform:rotate(180deg); }
.products-list { display:none; border-top:1px solid #f5f5f5; padding-top:10px; }
.products-list.open { display:block; }
.product-item { display:flex; justify-content:space-between; padding:6px 0; font-size:13px; color:#555; border-bottom:1px dashed #f0f0f0; }
.product-item:last-child { border-bottom:none; }
.product-item .pname { flex:1; }
.product-item .pprice { color:#e64340; font-weight:600; margin-left:12px; white-space:nowrap; }
.order-footer-row { display:flex; justify-content:space-between; align-items:center; padding-top:10px; border-top:1px solid #f0f0f0; margin-top:4px; }
.order-footer-row .price-big { font-size:22px; color:#e64340; font-weight:700; }
.date-filter { display:flex; align-items:center; gap:12px; padding:14px 20px; background:#fff; border-radius:12px; margin-bottom:16px; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
.date-filter label { font-size:14px; color:#666; font-weight:500; }
.date-filter input[type="date"] { padding:8px 12px; border:1.5px solid #e0e0e0; border-radius:8px; font-size:14px; outline:none; color:#333; }
.date-filter input[type="date"]:focus { border-color:#e64340; }
.date-filter .btn { padding:8px 16px; background:#e64340; color:#fff; border:none; border-radius:8px; font-size:14px; cursor:pointer; }
.sim-btn { padding:5px 12px; border:none; border-radius:16px; font-size:12px; cursor:pointer; color:#fff; }
</style>

<div class="header">
    <h1>📋 订单管理</h1>
    <span class="welcome"><?= $filterDate === date('Y-m-d') ? '今日' : $filterDate ?>共 <?= $todayTotal ?> 个订单</span>
</div>

<?php if ($msg): ?>
<div class="msg-box msg-success" style="margin-bottom:16px;">✓ <?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- 日期筛选 -->
<div class="date-filter">
    <form method="get" style="display:flex;align-items:center;gap:10px;flex:1;">
        <label>📅 筛选日期</label>
        <input type="date" name="date" value="<?= $filterDate ?>" max="<?= date('Y-m-d') ?>" />
        <button type="submit" class="btn">查看</button>
        <?php if ($filterDate !== date('Y-m-d')): ?>
        <a href="orders.php" class="btn btn-secondary" style="background:#f5f5f5;color:#666;text-decoration:none;padding:8px 16px;border-radius:8px;font-size:14px;">回到今日</a>
        <?php endif; ?>
    </form>
    <span style="font-size:13px;color:#bbb;"><?= $filterDate === date('Y-m-d') ? '今天' : date('m月d日', strtotime($filterDate)) ?>的订单</span>
</div>

<?php if (empty($orders)): ?>
<div style="text-align:center;padding:60px 0;color:#ccc;font-size:15px;">
    <div style="font-size:40px;margin-bottom:12px;">📦</div>
    <?= $filterDate === date('Y-m-d') ? '今日暂无订单' : '该日期暂无订单' ?>
</div>
<?php else: foreach ($orders as $o): ?>
<?php
    $st = $statusMap[$o['status']] ?? ['未知', '#999', '#f5f5f5'];
    $items = json_decode($o['items'] ?? '[]', true);
    $seq = isset($todayIndex[$o['id']]) ? str_pad($todayIndex[$o['id']] + 12, 3, '0', STR_PAD_LEFT) : '013';
    $createdTs = strtotime($o['created_at']);
    $dateStr = date('Y-m-d', $createdTs);
    $timeStr = date('H:i', $createdTs);
?>
<div class="order-card" id="order-<?= $o['id'] ?>">

    <!-- 流水号 -->
    <div class="order-serial">
        <span class="serial-num">TZ<?= $seq ?></span>
        <span class="serial-time"><?= $timeStr ?></span>
    </div>

    <!-- 收件人信息 -->
    <div class="order-body">
        <div class="info-row">
            <span class="info-label">收件人</span>
            <span class="info-value"><?= htmlspecialchars($o['user_name'] ?: '—') ?> · <?= htmlspecialchars($o['user_phone'] ?: '—') ?></span>
        </div>
        <?php if (!empty($o['address'])): ?>
        <div class="info-row" style="margin-bottom:0;">
            <span class="info-label">收货地</span>
            <span class="info-value" style="color:#666;"><?= htmlspecialchars($o['address']) ?></span>
        </div>
        <?php endif; ?>

        <!-- 状态 + 操作按钮 -->
        <div class="divider"></div>
        <div class="info-row">
            <span class="info-label">状态</span>
            <div style="display:flex;align-items:center;gap:10px;flex:1;flex-wrap:wrap;">
                <span class="tag-status" style="background:<?= $st[2] ?>;color:<?= $st[1] ?>;"><?= $st[0] ?></span>
                <?php if ($o['delivery_type'] !== 'delivery' && $o['status'] === 'paid'): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('确认顾客已到店自提？')">
                    <input type="hidden" name="action" value="complete_pickup" />
                    <input type="hidden" name="id" value="<?= $o['id'] ?>" />
                    <input type="hidden" name="date" value="<?= $filterDate ?>" />
                    <button type="submit" style="padding:6px 16px;background:#4caf50;color:#fff;border:none;border-radius:20px;font-size:13px;font-weight:600;cursor:pointer;box-shadow:0 2px 4px rgba(76,175,80,0.3);">✅ 自提完成</button>
                </form>
                <?php endif; ?>
                <?php if ($o['delivery_type'] === 'delivery' && $o['status'] === 'paid' && strpos($o['remark'] ?? '', '[配送员:') === false): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('确认有货？将生成派单信息，请前往聚合平台手动下单。')">
                    <input type="hidden" name="action" value="dispatch" />
                    <input type="hidden" name="id" value="<?= $o['id'] ?>" />
                    <input type="hidden" name="order_no" value="<?= htmlspecialchars($o['order_no']) ?>" />
                    <input type="hidden" name="date" value="<?= $filterDate ?>" />
                    <button type="submit" style="padding:6px 16px;background:#2196f3;color:#fff;border:none;border-radius:20px;font-size:13px;font-weight:600;cursor:pointer;box-shadow:0 2px 4px rgba(33,150,243,0.3);">🚴 发配送</button>
                </form>
                <?php endif; ?>
                <?php if (strpos($o['remark'] ?? '', '[配送员:') !== false): ?>
                <span style="font-size:12px;color:#ff9800;font-weight:600;">✅ 已发配送</span>
                <?php endif; ?>
                <?php if ($o['delivery_type'] === 'delivery' && $o['status'] === 'delivering' && !empty($o['rider_name'])): ?>
                <span style="font-size:12px;color:#00bcd4;">👤 骑手: <?= htmlspecialchars($o['rider_name']) ?> <?= htmlspecialchars($o['rider_phone']) ?></span>
                <?php endif; ?>
                <?php if ($o['delivery_type'] === 'delivery' && !empty($o['delivery_status'])): ?>
                <span class="tag-status" style="background:#f3e5f5;color:#9c27b0;font-size:11px;">
                    <?php
                    $dsMap = [
                        'accepted'  => '📋 商家已接单',
                        'dispatched'=> '📞 正在召唤骑手',
                        'picked_up' => '🚴 骑手已接单',
                        'delivered' => '✅ 骑手已取货',
                    ];
                    echo $dsMap[$o['delivery_status']] ?? $o['delivery_status'];
                    ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!empty($o['remark'])): ?>
        <div class="info-row" style="margin-bottom:0;">
            <span class="info-label">备注</span>
            <span class="remark-val"><?= nl2br(htmlspecialchars($o['remark'])) ?></span>
        </div>
        <?php endif; ?>

        <!-- 商品折叠区 -->
        <?php if (!empty($items)): ?>
        <div class="products-toggle" onclick="toggleProducts(this)">
            <span>📦 商品详情（<?= count($items) ?>件）</span>
            <span class="arrow">▼</span>
        </div>
        <div class="products-list">
            <?php foreach ($items as $item): ?>
            <div class="product-item">
                <span class="pname"><?= htmlspecialchars($item['name'] ?? '') ?> × <?= $item['count'] ?? 1 ?><?php if (!empty($item['spec'])) echo ' (' . htmlspecialchars($item['spec']) . ')'; ?></span>
                <span class="pprice">¥<?= number_format($item['sale_price'] ?? $item['price'] ?? 0, 2) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- 金额 + 配送方式 -->
        <div class="order-footer-row">
            <div>
                <span style="font-size:13px;color:#888;">合计 </span>
                <span class="price-big">¥<?= number_format($o['final_price'], 2) ?></span>
                <?php if ($o['delivery_type'] === 'delivery'): ?>
                <span style="font-size:12px;color:#00bcd4;margin-left:8px;">🚴 跑腿</span>
                <?php else: ?>
                <span style="font-size:12px;color:#e65100;margin-left:8px;font-weight:700;">🏪 取货码：TZ<?= $seq ?></span>
                <?php endif; ?>
            </div>
            <span style="font-size:11px;color:#bbb;"><?= htmlspecialchars($o['order_no']) ?></span>
        </div>

        <!-- 模拟配送控制（仅配送中订单） -->
        <?php if ($o['delivery_type'] === 'delivery' && $o['status'] === 'delivering'): ?>
        <div style="padding:12px 0;border-top:1px solid #f0f0f0;margin-top:8px;">
            <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
                <span style="font-size:12px;color:#999;">模拟配送：</span>
                <?php if ($o['delivery_status'] === 'accepted'): ?>
                <button class="sim-btn" onclick="simAction(<?= $o['id'] ?>, 'dispatched')" style="padding:5px 12px;background:#4caf50;color:#fff;border:none;border-radius:16px;font-size:12px;cursor:pointer;">📞 正在召唤骑手</button>
                <?php endif; ?>
                <?php if ($o['delivery_status'] === 'dispatched'): ?>
                <button class="sim-btn" onclick="simAction(<?= $o['id'] ?>, 'picked_up')" style="padding:5px 12px;background:#ff9800;color:#fff;border:none;border-radius:16px;font-size:12px;cursor:pointer;">🚴 骑手已接单</button>
                <?php endif; ?>
                <?php if ($o['delivery_status'] === 'picked_up'): ?>
                <button class="sim-btn" onclick="simAction(<?= $o['id'] ?>, 'delivered')" style="padding:5px 12px;background:#9c27b0;color:#fff;border:none;border-radius:16px;font-size:12px;cursor:pointer;">✅ 骑手已取货</button>
                <?php endif; ?>
                <?php if ($o['delivery_status'] === 'delivered'): ?>
                <button class="sim-btn" onclick="simAction(<?= $o['id'] ?>, 'complete')" style="padding:5px 12px;background:#4caf50;color:#fff;border:none;border-radius:16px;font-size:12px;cursor:pointer;">✅ 完成订单</button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; endif; ?>

<?php if ($totalPages > 1): ?>
<div class="pagination" style="margin-top:8px;">
    <?php if ($page > 1): ?>
    <a href="?date=<?= $filterDate ?>&page=<?= $page - 1 ?>">‹ 上一页</a>
    <?php else: ?>
    <span class="disabled">‹ 上一页</span>
    <?php endif; ?>
    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
        <?= $i == $page ? "<span class='current'>$i</span>" : "<a href='?date=$filterDate&page=$i'>$i</a>" ?>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?>
    <a href="?date=<?= $filterDate ?>&page=<?= $page + 1 ?>">下一页 ›</a>
    <?php else: ?>
    <span class="disabled">下一页 ›</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
function toggleProducts(el) {
    var list = el.nextElementSibling;
    var arrow = el.querySelector('.arrow');
    list.classList.toggle('open');
    arrow.classList.toggle('open');
}

// 模拟配送操作
function simAction(orderId, action) {
    var btn = event.target;
    btn.disabled = true;
    var origText = btn.textContent;
    btn.textContent = '⏳ 处理中...';
    
    var fd = new FormData();
    fd.append('order_id', orderId);
    
    fetch('/api/delivery_sim.php?action=' + action, {
        method: 'POST',
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.code === 0) {
            location.reload();
        } else {
            alert(data.msg);
            btn.disabled = false;
            btn.textContent = origText;
        }
    })
    .catch(function() {
        alert('请求失败');
        btn.disabled = false;
        btn.textContent = origText;
    });
}
</script>

<?php
$pageContent = ob_get_clean();
$pageTitle = '订单管理 - 桃之后台';
include 'layout.php';

