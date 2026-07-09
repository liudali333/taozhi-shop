<?php
require_once 'config.php';
checkAuth();

// 加载微信配置（用于生成小程序码）
$wxConfig = require __DIR__ . '/../wechat_config.php';

$msg = '';
try {
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action === 'add') {
            $title = trim($_POST['title'] ?? '');
            $value = floatval($_POST['value'] ?? 0);
            $minAmount = floatval($_POST['min_amount'] ?? 0);
            $stock = intval($_POST['stock'] ?? 0);
            $startTime = $_POST['start_time'] ?: date('Y-m-d');
            $endTime = $_POST['end_time'] ?: date('Y-m-d', strtotime('+30 days'));

            $db->prepare("INSERT INTO coupons (title, value, min_amount, stock, status, start_time, end_time) VALUES (?, ?, ?, ?, 1, ?, ?)")
               ->execute([$title, $value, $minAmount, $stock, $startTime, $endTime]);

            header("Location: coupons.php?msg=添加成功");
            exit;
        } elseif ($action === 'delete') {
            $db->prepare("DELETE FROM coupons WHERE id = ?")->execute([intval($_POST['id'])]);
            header("Location: coupons.php?msg=已删除");
            exit;
        }
    }

    if (!empty($_GET['msg'])) $msg = $_GET['msg'];
    $coupons = $db->query("SELECT * FROM coupons ORDER BY id DESC")->fetchAll();

} catch (Exception $e) {
    $msg = '数据库错误: ' . $e->getMessage();
    $coupons = [];
}

// 生成小程序码的函数
function getMiniProgramQrCode($couponId, $wxConfig) {
    $appid = $wxConfig['appid'];
    $secret = $wxConfig['secret'];

    // 获取 access_token（cURL 方式，更稳定）
    $ch = curl_init("https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={$appid}&secret={$secret}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $tokenResp = curl_exec($ch);
    curl_close($ch);

    $tokenData = json_decode($tokenResp, true);
    if (empty($tokenData['access_token'])) {
        error_log("[CouponQR] access_token失败: " . $tokenResp);
        return null;
    }
    $accessToken = $tokenData['access_token'];

    // 生成无限制小程序码
    $ch = curl_init("https://api.weixin.qq.com/wxa/getwxacodeunlimit?access_token={$accessToken}");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'scene' => strval($couponId),
        'page' => 'pages/receive-coupon/receive-coupon',
        'width' => 280
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $qrImage = curl_exec($ch);
    curl_close($ch);

    // 返回JSON说明出错了
    if (strlen($qrImage) < 100 || strpos($qrImage, '{') === 0) {
        error_log("[CouponQR] 生成失败: " . $qrImage);
        return null;
    }

    // 保存到本地
    $qrPath = __DIR__ . '/qrcodes';
    if (!is_dir($qrPath)) mkdir($qrPath, 0777, true);
    $fileName = "coupon_{$couponId}.png";
    file_put_contents("{$qrPath}/{$fileName}", $qrImage);

    return "qrcodes/{$fileName}";
}

ob_start();
?>
<style>
.coupon-card { position: relative; }
.qrcode-btn { margin-left: 8px; background: #1976d2; }
.qrcode-btn:hover { background: #1565c0; }
.qrcode-img { max-width: 200px; border-radius: 8px; margin-top: 12px; }
.qrcode-hint { background: #e3f2fd; border: 1px solid #90caf9; border-radius: 8px; padding: 12px; margin-bottom: 12px; font-size: 13px; color: #1565c0; line-height: 1.6; }
.qrcode-hint strong { color: #0d47a1; }
</style>

<div class="header"><h1>🎫 优惠券管理</h1></div>

<div class="card">
    <?php if ($msg): ?><div class="msg-box msg-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <form method="POST" style="margin-bottom:24px;padding:16px;background:#fafafa;border-radius:10px;">
        <input type="hidden" name="action" value="add">
        <div style="font-weight:600;margin-bottom:12px;">+ 添加满减券</div>
        <div class="form-row"><label class="form-label">优惠券名称</label><input type="text" name="title" class="form-input" placeholder="例：新人满59减10" required></div>
        <div class="form-row form-row-inline-3">
            <div><label class="form-label">优惠金额（元）</label><input type="number" name="value" class="form-input" step="0.01" min="0.01" placeholder="10" required></div>
            <div><label class="form-label">最低消费（元）</label><input type="number" name="min_amount" class="form-input" step="0.01" min="0" placeholder="59"></div>
            <div><label class="form-label">库存（张）</label><input type="number" name="stock" class="form-input" value="100" min="1"></div>
        </div>
        <div class="form-row form-row-inline-2">
            <div><label class="form-label">开始日期</label><input type="date" name="start_time" class="form-input" value="<?= date('Y-m-d') ?>"></div>
            <div><label class="form-label">结束日期</label><input type="date" name="end_time" class="form-input" value="<?= date('Y-m-d', strtotime('+30 days')) ?>"></div>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn-primary">添加优惠券</button></div>
    </form>

    <div style="margin-bottom:12px;font-weight:600;">已有优惠券（<?= count($coupons) ?>张）</div>
    <?php foreach ($coupons as $c): ?>
    <div class="coupon-card">
        <div class="coupon-value">¥<?= number_format($c['value'], 0) ?></div>
        <div class="coupon-info">
            <div class="coupon-title"><?= htmlspecialchars($c['title']) ?></div>
            <div class="coupon-desc">满<?= number_format($c['min_amount'], 0) ?>元可用 · 库存<?= $c['stock'] ?>张 · <?= substr($c['start_time'] ?? '', 0, 10) ?> 至 <?= substr($c['end_time'] ?? '', 0, 10) ?></div>
        </div>
        <span class="tag <?= $c['status'] ? 'tag-on' : 'tag-off' ?>"><?= $c['status'] ? '可用' : '已禁用' ?></span>
        <button class="btn qrcode-btn btn-sm" onclick="showQrcode(<?= $c['id'] ?>)">📋 二维码</button>
        <a href="users.php?coupon_id=<?= $c['id'] ?>" class="btn btn-sm" style="background:#e8f5e9;color:#388e3c;text-decoration:none;">🎁 发券</a>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('确认删除？')">删除</button>
        </form>
    </div>
    <div id="qr-<?= $c['id'] ?>" style="display:none;text-align:center;padding:16px;background:#fafafa;border-radius:10px;margin-bottom:12px;">
        <div class="qrcode-hint">
            <strong>📱 这是小程序码</strong>（不是普通二维码）<br>
            用微信扫描后自动领取优惠券并发送订阅通知<br>
            <span style="font-size:12px;color:#666;">本地测试：微信开发者工具 → 导入项目 → 编译模式 → 扫码编译</span>
        </div>
        <img class="qrcode-img" src="/api/coupon.php?action=get_qrcode&id=<?= $c['id'] ?>&t=<?= time() ?>" alt="小程序码" onerror="this.style.display='none';this.nextElementSibling.style.display='block';">
        <div style="margin-top:12px;color:#c62828;font-size:13px;display:none;">生成失败，请检查 AppID 和 AppSecret 配置是否正确</div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($coupons)): ?><div style="text-align:center;padding:40px;color:#999;">暂无优惠券</div><?php endif; ?>
</div>

<script>
function showQrcode(id) {
    var el = document.getElementById('qr-' + id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>

<?php
$pageContent = ob_get_clean();
$pageTitle = '优惠券管理 - 桃之后台';
include 'layout.php';
