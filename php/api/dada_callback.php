<?php
/**
 * 达达回调处理
 *
 * 达达在订单状态变更时 POST 到此接口，包含骑手信息、位置等
 *
 * 使用方法：在 wechat_config.php 中配置 dada_callback 指向此文件
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../wechat_config.php';

$config = require __DIR__ . '/../wechat_config.php';
$appSecret = $config['dada_app_secret'] ?? '';

// 只接受 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit('{"code":1,"msg":"method not allowed"}');
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    exit('{"code":1,"msg":"invalid json"}');
}

// 验证签名
$client_id  = $data['client_id'] ?? '';
$order_id   = $data['order_id'] ?? '';
$update_time = $data['update_time'] ?? '';

$list = [$client_id, $order_id, $update_time];
sort($list);
$joinedStr = implode('', $list);
$expectedSign = md5($joinedStr);

if (($data['signature'] ?? '') !== $expectedSign) {
    error_log("[dada callback] signature mismatch: expected=$expectedSign, got=" . ($data['signature'] ?? ''));
    exit('{"code":1,"msg":"signature mismatch"}');
}

$orderStatus = intval($data['order_status'] ?? 0);

// 达达订单状态码含义：
// 100 = 骑手已接单
// 200 = 骑手到店
// 300 = 骑手取货完成
// 400 = 用户签收
// 500 = 订单取消
// 1000 = 创建失败

try {
    $db = getDB();

    // 根据 origin_id 找到本地订单
    $stmt = $db->prepare("SELECT * FROM orders WHERE order_no = ? LIMIT 1");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        error_log("[dada callback] order not found: $order_id");
        exit('{"code":0,"msg":"ok"}'); // 即使订单不存在也返回成功，避免达达重试
    }

    switch ($orderStatus) {
        case 100: // 骑手已接单
            $riderName = $data['dm_name'] ?? '';
            $riderPhone = $data['dm_mobile'] ?? '';
            $riderLat = floatval($data['transporter_lat'] ?? 0);
            $riderLng = floatval($data['transporter_lng'] ?? 0);

            $stmt = $db->prepare("UPDATE orders SET
                status = 'delivering',
                rider_name = ?, rider_phone = ?,
                rider_lat = ?, rider_lng = ?,
                delivery_status = 'rider_assigned',
                delivery_time = FROM_UNIXTIME(?)
                WHERE order_no = ?");
            $stmt->execute([$riderName, $riderPhone, $riderLat, $riderLng, $update_time, $order_id]);
            break;

        case 200: // 骑手到店
            $stmt = $db->prepare("UPDATE orders SET delivery_status = 'at_store' WHERE order_no = ?");
            $stmt->execute([$order_id]);
            break;

        case 300: // 骑手取货完成
            $stmt = $db->prepare("UPDATE orders SET delivery_status = 'picked_up' WHERE order_no = ?");
            $stmt->execute([$order_id]);
            break;

        case 400: // 用户签收
            $stmt = $db->prepare("UPDATE orders SET
                status = 'completed',
                delivery_status = 'delivered'
                WHERE order_no = ?");
            $stmt->execute([$order_id]);
            break;

        case 500: // 订单取消
            $cancelReason = $data['cancel_reason'] ?? '用户取消';
            $stmt = $db->prepare("UPDATE orders SET
                status = 'cancelled',
                delivery_status = 'cancelled',
                remark = CONCAT(IFNULL(remark, ''), '[达达取消: ' . ? . ']')
                WHERE order_no = ?");
            $stmt->execute([$cancelReason, $order_id]);
            break;

        case 1000: // 创建失败
            $stmt = $db->prepare("UPDATE orders SET
                delivery_status = 'dispatch_failed',
                remark = CONCAT(IFNULL(remark, ''), '[达达下单失败: ' . ? . ']')
                WHERE order_no = ?");
            $stmt->execute([$data['cancel_reason'] ?? '未知', $order_id]);
            break;

        default:
            error_log("[dada callback] unknown status $orderStatus for order $order_id");
            break;
    }

    exit('{"code":0,"msg":"ok"}');

} catch (Exception $e) {
    error_log("[dada callback] error: " . $e->getMessage());
    exit('{"code":1,"msg":"error"}');
}
