<?php
/**
 * 模拟配送管理 API
 * 5步流程：商家已接单 → 正在召唤骑手 → 骑手已接单 → 骑手已取货 → 已完成
 * 等聚合平台 API 开通后替换为真实对接
 */

require_once __DIR__ . '/db.php';

// 统一响应函数
function response($code = 0, $msg = '', $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// 权限校验：管理员登录
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    response(1, '未授权');
}

// 数据库连接
$db = getDB();

$action = $_GET['action'] ?? '';

if (empty($_POST['order_id'])) {
    response(1, '缺少订单ID');
}
$orderId = intval($_POST['order_id']);

$stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    response(1, '订单不存在');
}

// 从门店表读取真实坐标（下单时未写入订单表）
$stmtStore = $db->prepare("SELECT lat, lng FROM stores WHERE id = ?");
$stmtStore->execute([$order['store_id']]);
$store = $stmtStore->fetch(PDO::FETCH_ASSOC);
$storeLat = floatval($store['lat'] ?? 0);
$storeLng = floatval($store['lng'] ?? 0);
$userLat  = floatval($order['user_lat'] ?? 0);
$userLng  = floatval($order['user_lng'] ?? 0);

$now = date('Y-m-d H:i:s');

switch ($action) {
    case 'dispatched':
        // 商家已接单 → 正在召唤骑手
        if (($order['delivery_status'] ?? '') !== 'accepted') {
            response(1, '状态不对，当前不是商家已接单');
        }
        $stmt = $db->prepare("
            UPDATE orders SET
                delivery_status = 'dispatched',
                remark = CONCAT(IFNULL(remark, ''), '\n[配送员:正在召唤骑手]')
            WHERE id = ?
        ");
        $stmt->execute([$orderId]);
        response(0, '正在召唤骑手');
        break;

    case 'picked_up':
        // 正在召唤骑手 → 骑手已接单（写入骑手信息）
        if (($order['delivery_status'] ?? '') !== 'dispatched') {
            response(1, '状态不对，当前不是正在召唤骑手');
        }
        // 骑手接单后：在门店附近200-800m随机位置
        $riderLat = $storeLat + (mt_rand(-800, 800) / 1000000);
        $riderLng = $storeLng + (mt_rand(-800, 800) / 1000006);
        $riderName = '骑手';
        $riderPhone = '17622223333';
        $stmt = $db->prepare("
            UPDATE orders SET
                rider_name = ?, rider_phone = ?,
                rider_lat = ?, rider_lng = ?,
                delivery_status = 'picked_up',
                remark = CONCAT(IFNULL(remark, ''), '\n[配送员:骑手已接单]')
            WHERE id = ?
        ");
        $stmt->execute([$riderName, $riderPhone, $riderLat, $riderLng, $orderId]);
        response(0, '骑手已接单');
        break;

    case 'delivered':
        // 骑手已接单 → 骑手已取货（骑手移动到路线中段）
        if (($order['delivery_status'] ?? '') !== 'picked_up') {
            response(1, '状态不对，当前不是骑手已接单');
        }
        if ($storeLat && $userLat) {
            // 路线中段 ±300m 随机偏移
            $midLat = ($storeLat + $userLat) / 2 + (mt_rand(-300, 300) / 1000000);
            $midLng = ($storeLng + $userLng) / 2 + (mt_rand(-300, 300) / 1000006);
        } else {
            $midLat = $storeLat + (mt_rand(-500, 500) / 1000000);
            $midLng = $storeLng + (mt_rand(-500, 500) / 1000006);
        }
        $stmt = $db->prepare("
            UPDATE orders SET
                rider_lat = ?, rider_lng = ?,
                delivery_status = 'delivered',
                remark = CONCAT(IFNULL(remark, ''), '\n[配送员:骑手已取货]')
            WHERE id = ?
        ");
        $stmt->execute([$midLat, $midLng, $orderId]);
        response(0, '骑手已取货');
        break;

    case 'complete':
        // 骑手已取货 → 已完成
        if (($order['delivery_status'] ?? '') !== 'delivered') {
            response(1, '状态不对，当前不是骑手已取货');
        }
        $stmt = $db->prepare("
            UPDATE orders SET
                status = 'completed',
                delivery_status = 'delivered',
                completed_at = ?,
                remark = CONCAT(IFNULL(remark, ''), '\n[配送员:已完成]')
            WHERE id = ?
        ");
        $stmt->execute([$now, $orderId]);
        response(0, '订单已完成');
        break;

    default:
        response(1, '未知操作');
        break;
}
