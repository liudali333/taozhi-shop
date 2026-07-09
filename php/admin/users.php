<?php
require_once 'config.php';
checkAuth();

$msg = '';
$search = trim($_GET['search'] ?? '');
$preselectCoupon = intval($_GET['coupon_id'] ?? 0);
$db = getDB();

// 处理发券
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'push_coupon') {
    $userId = intval($_POST['user_id'] ?? 0);
    $couponId = intval($_POST['coupon_id'] ?? 0);
    if ($userId && $couponId) {
        try {
            $stmt = $db->prepare("SELECT * FROM coupons WHERE id = ? AND status = 1");
            $stmt->execute([$couponId]);
            $coupon = $stmt->fetch();
            if (!$coupon) {
                $msg = '优惠券不存在';
            } elseif ($coupon['stock'] <= 0) {
                $msg = '库存不足';
            } else {
                $stmt = $db->prepare("SELECT id FROM user_coupons WHERE user_id = ? AND coupon_id = ?");
                $stmt->execute([$userId, $couponId]);
                if ($stmt->fetch()) {
                    $msg = '该用户已领取过此券';
                } else {
                    $db->beginTransaction();
                    $db->prepare("INSERT INTO user_coupons (user_id, coupon_id, status, created_at) VALUES (?, ?, 'usable', NOW())")
                       ->execute([$userId, $couponId]);
                    $db->prepare("UPDATE coupons SET stock = stock - 1 WHERE id = ?")
                       ->execute([$couponId]);
                    $db->commit();
                    $msg = '发放成功';
                }
            }
        } catch (Exception $e) {
            $msg = '发放失败：' . $e->getMessage();
        }
        header("Location: users.php?msg=" . urlencode($msg) . ($search ? '&search=' . urlencode($search) : ''));
        exit;
    }
}

if (!empty($_GET['msg'])) $msg = $_GET['msg'];

// 查询用户列表
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 20;
$offset = ($page - 1) * $pageSize;

$where = "1=1";
$params = [];
if ($search) {
    $where .= " AND (nickname LIKE ? OR openid LIKE ?)";
    $params = ["%$search%", "%$search%"];
}

$total = $db->prepare("SELECT COUNT(*) FROM users WHERE $where");
$total->execute($params);
$totalCount = $total->fetchColumn();

$stmt = $db->prepare("SELECT * FROM users WHERE $where ORDER BY id DESC LIMIT $pageSize OFFSET $offset");
$stmt->execute($params);
$users = $stmt->fetchAll();

// 所有优惠券（下拉用）
$coupons = $db->query("SELECT id, title, value, min_amount, stock FROM coupons WHERE status = 1 ORDER BY id DESC")->fetchAll();

$totalPages = ceil($totalCount / $pageSize);

$preselCoupon = null;
if ($preselectCoupon) {
    foreach ($coupons as $c) { if ($c['id'] == $preselectCoupon) { $preselCoupon = $c; break; } }
}

ob_start();
?>
<div class="header"><h1>👥 用户管理</h1></div>

<?php if ($msg): ?>
<div class="msg-box msg-success" style="margin-bottom:16px;">✓ <?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<?php if ($preselCoupon): ?>
<div style="background:#fff3e0;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:14px;color:#e65100;">
    🎁 正在向用户发放：<strong><?= htmlspecialchars($preselCoupon['title']) ?></strong>（￥<?= $preselCoupon['value'] ?> 满<?= $preselCoupon['min_amount'] ?>）
    · <a href="users.php" style="color:#999;text-decoration:none;">取消选择</a>
</div>
<?php endif; ?>

<!-- 搜索栏 -->
<div class="card" style="padding:16px;margin-bottom:16px;">
    <form method="get" style="display:flex;gap:12px;align-items:center;">
        <input name="search" value="<?= htmlspecialchars($search) ?>" class="form-input" placeholder="搜索昵称 / OpenID" style="flex:1;" />
        <button type="submit" class="btn btn-primary">🔍 搜索</button>
        <?php if ($search): ?>
        <a href="users.php" class="btn btn-secondary">清除</a>
        <?php endif; ?>
        <span style="font-size:13px;color:#999;white-space:nowrap;">共 <?= $totalCount ?> 人</span>
    </form>
</div>

<!-- 用户列表 -->
<div class="card">
    <table>
        <thead>
            <tr>
                <th>用户</th>
                <th>注册时间</th>
                <th>发放优惠券</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($users)): ?>
            <tr><td colspan="3" style="text-align:center;color:#999;padding:40px;">暂无用户</td></tr>
            <?php else: foreach ($users as $u): ?>
            <tr>
                <td>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <?php if (!empty($u['avatar'])): ?>
                        <img src="<?= htmlspecialchars($u['avatar']) ?>" style="width:36px;height:36px;border-radius:50%;object-fit:cover;" />
                        <?php else: ?>
                        <div style="width:36px;height:36px;border-radius:50%;background:#f0f0f0;display:flex;align-items:center;justify-content:center;font-size:16px;">👤</div>
                        <?php endif; ?>
                        <div>
                            <div style="font-size:14px;color:#333;font-weight:500;"><?= htmlspecialchars($u['nickname'] ?: '微信用户') ?></div>
                            <div style="font-size:11px;color:#bbb;font-family:monospace;"><?= htmlspecialchars($u['openid']) ?></div>
                        </div>
                    </div>
                </td>
                <td style="color:#999;font-size:13px;"><?= $u['created_at'] ?></td>
                <td>
                    <?php if (!empty($coupons)): ?>
                    <form method="post" style="display:flex;gap:8px;align-items:center;" onsubmit="return confirm('确认向【<?= htmlspecialchars($u['nickname'] ?: '该用户') ?>】发放优惠券？')">
                        <input type="hidden" name="action" value="push_coupon" />
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>" />
                        <select name="coupon_id" class="form-select" style="min-width:160px;padding:6px 10px;font-size:13px;" required>
                            <option value="">选择优惠券</option>
                            <?php foreach ($coupons as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $c['id'] == $preselectCoupon ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['title']) ?>（￥<?= $c['value'] ?> 满<?= $c['min_amount'] ?>）剩<?= $c['stock'] ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-danger btn-sm">发放</button>
                    </form>
                    <?php else: ?>
                    <span style="color:#999;font-size:13px;">暂无可用优惠券</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?>
    <a href="?page=<?= $page-1 ?><?= $search ? '&search='.urlencode($search) : '' ?>">上一页</a>
    <?php else: ?>
    <span class="disabled">上一页</span>
    <?php endif; ?>

    <?php for ($i = max(1, $page-2); $i <= min($totalPages, $page+2); $i++): ?>
        <?php if ($i == $page): ?>
        <span class="current"><?= $i ?></span>
        <?php else: ?>
        <a href="?page=<?= $i ?><?= $search ? '&search='.urlencode($search) : '' ?>"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>

    <?php if ($page < $totalPages): ?>
    <a href="?page=<?= $page+1 ?><?= $search ? '&search='.urlencode($search) : '' ?>">下一页</a>
    <?php else: ?>
    <span class="disabled">下一页</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
$pageContent = ob_get_clean();
$pageTitle = '用户管理 - 桃之后台';
include 'layout.php';
