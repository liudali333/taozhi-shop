<?php
/**
 * 订单接口
 */
require_once __DIR__ . '/db.php';

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

function response($code = 0, $msg = '', $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

// 解析 POST 兼容 JSON / form-urlencoded
function parsePost() {
    if (!empty($_POST)) return $_POST;
    $input = file_get_contents('php://input');
    if (!$input) return [];
    $json = json_decode($input, true);
    if (is_array($json)) return $json;
    parse_str($input, $data);
    return is_array($data) ? $data : [];
}

/**
 * 确保 orders 表结构兼容当前业务
 * 字段：id, order_no, user_id, store_id, store_name, total_price, delivery_fee,
 *       final_price, delivery_type, status, items(JSON), user_name, user_phone,
 *       address, remark, user_lat, user_lng, prepay_id, paid_at, transaction_id,
 *       created_at
 */
function ensureOrdersTable($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_no VARCHAR(32) UNIQUE NOT NULL,
        user_id INT DEFAULT 0,
        store_id INT DEFAULT 0,
        store_name VARCHAR(128) DEFAULT '',
        total_price DECIMAL(10,2) DEFAULT 0,
        delivery_fee DECIMAL(10,2) DEFAULT 0,
        final_price DECIMAL(10,2) DEFAULT 0,
        delivery_type VARCHAR(20) DEFAULT 'self',
        status VARCHAR(20) DEFAULT 'pending',
        items TEXT,
        user_name VARCHAR(64) DEFAULT '',
        user_phone VARCHAR(32) DEFAULT '',
        address VARCHAR(512) DEFAULT '',
        remark VARCHAR(512) DEFAULT '',
        user_lat DECIMAL(10,6) DEFAULT 0,
        user_lng DECIMAL(10,6) DEFAULT 0,
        prepay_id VARCHAR(128) DEFAULT '',
        paid_at DATETIME DEFAULT NULL,
        rider_name VARCHAR(64) DEFAULT '',
        rider_phone VARCHAR(32) DEFAULT '',
        rider_lat DECIMAL(10,6) DEFAULT 0,
        rider_lng DECIMAL(10,6) DEFAULT 0,
        delivery_status VARCHAR(20) DEFAULT '',
        pickup_time DATETIME DEFAULT NULL,
        delivery_time DATETIME DEFAULT NULL,
        transaction_id VARCHAR(64) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_status (status),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 补字段（如果表是老版本，缺一些字段）
    $cols = [
        'user_id'        => 'INT DEFAULT 0',
        'store_id'       => 'INT DEFAULT 0',
        'store_name'     => 'VARCHAR(128) DEFAULT ""',
        'delivery_fee'   => 'DECIMAL(10,2) DEFAULT 0',
        'final_price'    => 'DECIMAL(10,2) DEFAULT 0',
        'delivery_type'  => 'VARCHAR(20) DEFAULT "self"',
        'remark'         => 'VARCHAR(512) DEFAULT ""',
        'prepay_id'      => 'VARCHAR(128) DEFAULT ""',
        'paid_at'        => 'DATETIME DEFAULT NULL',
        'transaction_id' => 'VARCHAR(64) DEFAULT ""',
        'rider_name'     => 'VARCHAR(64) DEFAULT ""',
        'rider_phone'    => 'VARCHAR(32) DEFAULT ""',
        'rider_lat'      => 'DECIMAL(10,6) DEFAULT 0',
        'rider_lng'      => 'DECIMAL(10,6) DEFAULT 0',
        'delivery_status'=> 'VARCHAR(20) DEFAULT ""',
        'pickup_time'    => 'DATETIME DEFAULT NULL',
        'delivery_time'  => 'DATETIME DEFAULT NULL',
    ];
    foreach ($cols as $col => $def) {
        try {
            $db->exec("ALTER TABLE orders ADD COLUMN {$col} {$def}");
        } catch (Exception $e) {
            // 字段已存在则忽略
        }
    }
}

try {
    $db = getDB();
    ensureOrdersTable($db);

    $action = $_GET['action'] ?? '';

    // ============= 自动取消15分钟未付款订单（可被 cron 或任意订单操作触发）=============
    if ($action === 'cleanup_expired') {
        $stmt = $db->prepare("
            UPDATE orders
            SET status = 'cancelled'
            WHERE status = 'pending'
              AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmt->execute();
        $cancelled = $stmt->rowCount();
        // 记录日志（方便排查）
        error_log("[order cleanup] " . date('Y-m-d H:i:s') . " cancelled {$cancelled} expired orders\n");
        response(0, 'ok', ['cancelled' => $cancelled]);
        exit;
    }

    // ============= 手动发配送（后台触发）=============
    if (($action === 'dispatch' || (($_POST['action'] ?? '') === 'dispatch')) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = parsePost();
        $orderNo = trim($input['order_no'] ?? '');
        if (!$orderNo) response(1, 'order_no 必填');

        // 查询订单
        $stmt = $db->prepare("SELECT * FROM orders WHERE order_no = ?");
        $stmt->execute([$orderNo]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) response(1, '订单不存在');
        if ($order['status'] !== 'paid') response(1, '订单状态不是已付款，无法发配送');
        if ($order['delivery_type'] !== 'delivery') response(1, '这不是配送订单，无法发配送');

        // 加载达达模块
        require_once __DIR__ . '/delivery.php';

        // 调用达达下单
        $result = createDadaOrder(
            $db,
            $orderNo,
            $order['user_name'],
            $order['address'],
            floatval($order['user_lat'] ?? 0),
            floatval($order['user_lng'] ?? 0),
            $order['user_phone'],
            floatval($order['total_price'] ?? 0)
        );

        if (!$result['success']) {
            response(1, '发配送失败：' . $result['error']);
        }

        // 更新订单备注（记录达达单号）
        $dadaOrderId = $result['dada_order_id'];
        $newRemark = ($order['remark'] ?? '') . ' [达达单号:' . $dadaOrderId . ']';
        $stmt = $db->prepare("UPDATE orders SET remark = ? WHERE order_no = ?");
        $stmt->execute([$newRemark, $orderNo]);

        response(0, '发配送成功', [
            'dada_order_id' => $dadaOrderId,
            'message' => '骑手接单后将推送骑手信息',
        ]);
        exit;
    }

    // ============= 创建订单 =============
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
        $input = parsePost();

        $userId       = intval($input['user_id'] ?? 0);
        $storeId      = intval($input['store_id'] ?? 0);
        $storeName    = trim($input['store_name'] ?? '');
        $items        = $input['products'] ?? ($input['items'] ?? []);
        $totalPrice   = floatval($input['total_price'] ?? 0);
        $deliveryFee  = floatval($input['delivery_fee'] ?? 0);
        $finalPrice   = floatval($input['final_price'] ?? ($totalPrice + $deliveryFee));
        $deliveryType = in_array($input['delivery_type'] ?? '', ['self', 'delivery'])
                        ? $input['delivery_type'] : 'self';
        $remark       = trim($input['remark'] ?? '');
        $userName     = trim($input['consignee_name'] ?? ($input['user_name'] ?? ''));
        $userPhone    = trim($input['consignee_phone'] ?? ($input['user_phone'] ?? ''));
        $address      = trim($input['consignee_address'] ?? ($input['address'] ?? ''));
        $userLat      = floatval($input['user_lat'] ?? 0);
        $userLng      = floatval($input['user_lng'] ?? 0);

        $orderNo = 'TZ' . date('YmdHis') . rand(1000, 9999);
        $status = 'pending';

        $stmt = $db->prepare("INSERT INTO orders
            (order_no, user_id, store_id, store_name, total_price, delivery_fee, final_price,
             delivery_type, status, items, user_name, user_phone, address, remark,
             user_lat, user_lng)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $orderNo, $userId, $storeId, $storeName,
            $totalPrice, $deliveryFee, $finalPrice,
            $deliveryType, $status,
            json_encode($items, JSON_UNESCAPED_UNICODE),
            $userName, $userPhone, $address, $remark,
            $userLat, $userLng
        ]);

        $orderId = $db->lastInsertId();

        // 计算流水号（TZ013 起）
        $stmtSeq = $db->prepare("SELECT created_at FROM orders WHERE id = ?");
        $stmtSeq->execute([$orderId]);
        $createdAt = $stmtSeq->fetchColumn();
        $createdDate = substr($createdAt, 0, 10);
        $stmtSeq2 = $db->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = ? AND created_at <= ?");
        $stmtSeq2->execute([$createdDate, $createdAt]);
        $seq = intval($stmtSeq2->fetchColumn()) + 12;
        $serialNo = 'TZ' . str_pad($seq, 3, '0', STR_PAD_LEFT);

        response(0, '下单成功', [
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'serial_no' => $serialNo,
        ]);
        exit;
    }

    // ============= 支付状态更新 =============
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'mark_paid') {
        $input = parsePost();
        $orderNo = trim($input['order_no'] ?? '');
        $transactionId = trim($input['transaction_id'] ?? '');
        if (!$orderNo) response(1, 'order_no 必填');
        // 支付成功：配送订单自动进入第一步
        $stmt = $db->prepare("UPDATE orders SET status = 'paid', paid_at = NOW(), transaction_id = ?, delivery_status = 'accepted' WHERE order_no = ? AND status = 'pending'");
        $stmt->execute([$transactionId, $orderNo]);
        response(0, 'ok');
        exit;
    }

    // ============= 取消订单 =============
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'cancel') {
        $input = parsePost();
        $orderNo = trim($input['order_no'] ?? '');
        if (!$orderNo) response(1, 'order_no 必填');
        $stmt = $db->prepare("UPDATE orders SET status = 'cancelled' WHERE order_no = ? AND status = 'pending'");
        $stmt->execute([$orderNo]);
        response(0, 'ok');
        exit;
    }

    // ============= 更新订单状态 =============
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_status') {
        $input = parsePost();
        $orderNo = trim($input['order_no'] ?? '');
        $newStatus = trim($input['status'] ?? 'cancelled');
        if (!$orderNo) response(1, 'order_no 必填');
        if (!in_array($newStatus, ['paid', 'delivering', 'completed', 'cancelled'])) {
            response(1, '无效状态');
        }
        $allowedFrom = ['pending' => ['paid', 'cancelled'], 'paid' => ['delivering', 'cancelled'], 'delivering' => ['completed']];
        // 找当前状态
        $stmt = $db->prepare("SELECT status FROM orders WHERE order_no = ?");
        $stmt->execute([$orderNo]);
        $current = $stmt->fetchColumn();
        if ($current === false) response(1, '订单不存在');
        if (!isset($allowedFrom[$current]) || !in_array($newStatus, $allowedFrom[$current])) {
            response(1, "当前状态[{$current}]不允许变更为[{$newStatus}]");
        }
        $stmt = $db->prepare("UPDATE orders SET status = ? WHERE order_no = ?");
        $stmt->execute([$newStatus, $orderNo]);
        response(0, 'ok');
        exit;
    }

    // ============= 订单详情 =============
    if ($action === 'detail') {
        $orderNo = trim($_GET['order_no'] ?? '');
        if ($orderNo) {
            // 顺便清理15分钟未付款订单
            $db->exec("
                UPDATE orders
                SET status = 'cancelled'
                WHERE status = 'pending'
                  AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            ");
            $stmt = $db->prepare("SELECT * FROM orders WHERE order_no = ?");
            $stmt->execute([$orderNo]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($order) {
                $order['items'] = json_decode($order['items'] ?? '[]', true) ?: [];

                // 计算每日流水号（TZ013 起）
                $createdDate = substr($order['created_at'], 0, 10);
                $stmtSeq = $db->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = ? AND created_at <= ?");
                $stmtSeq->execute([$createdDate, $order['created_at']]);
                $seq = intval($stmtSeq->fetchColumn()) + 12;
                $order['serial_no'] = 'TZ' . str_pad($seq, 3, '0', STR_PAD_LEFT);

                // 补充门店坐标
                $storeRow = $db->query("SELECT * FROM store_single WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
                if ($storeRow) {
                    $order['store_lat'] = floatval($storeRow['lat'] ?? 0);
                    $order['store_lng'] = floatval($storeRow['lng'] ?? 0);
                    $order['store_address'] = $storeRow['address'] ?? '';
                    $order['store_phone'] = $storeRow['phone'] ?? '';
                    $order['store_open_time'] = substr($storeRow['open_time'] ?? '09:00', 0, 5);
                    $order['store_close_time'] = substr($storeRow['close_time'] ?? '22:00', 0, 5);
                }

                response(0, 'success', $order);
            } else {
                response(1, '订单不存在');
            }
            exit;
        }
    }

    // ============= 刷新骑手位置（后台触发）=============
    if (($action === 'refresh_rider') && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = parsePost();
        $orderNo = trim($input['order_no'] ?? '');
        if (!$orderNo) response(1, 'order_no 必填');

        $stmt = $db->prepare("SELECT * FROM orders WHERE order_no = ?");
        $stmt->execute([$orderNo]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) response(1, '订单不存在');
        if ($order['status'] !== 'delivering') response(1, '订单不在配送中');

        // 从备注中提取达达单号
        preg_match('/达达单号:(\w+)/', $order['remark'] ?? '', $matches);
        if (!isset($matches[1])) response(1, '未找到达达单号');

        $dadaOrderId = $matches[1];
        require_once __DIR__ . '/delivery.php';
        $result = queryDadaOrder($dadaOrderId);

        if (isset($result['code']) && $result['code'] == 0) {
            // 更新骑手信息
            $riderName = $result['dm_name'] ?? $order['rider_name'];
            $riderPhone = $result['dm_mobile'] ?? $order['rider_phone'];
            $riderLat = floatval($result['transporter_lat'] ?? $order['rider_lat']);
            $riderLng = floatval($result['transporter_lng'] ?? $order['rider_lng']);

            $stmt = $db->prepare("UPDATE orders SET rider_name=?, rider_phone=?, rider_lat=?, rider_lng=? WHERE order_no=?");
            $stmt->execute([$riderName, $riderPhone, $riderLat, $riderLng, $orderNo]);

            response(0, 'success', [
                'rider_name' => $riderName,
                'rider_phone' => $riderPhone,
                'rider_lat' => $riderLat,
                'rider_lng' => $riderLng,
            ]);
        } else {
            response(1, '查询骑手位置失败：' . ($result['msg'] ?? ''));
        }
        exit;
    }

    // ============= 订单列表 =============
    // 顺便清理15分钟未付款订单（被动触发，不需要单独 crontab）
    $db->exec("
        UPDATE orders
        SET status = 'cancelled'
        WHERE status = 'pending'
          AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ");

    $userId = intval($_GET['user_id'] ?? 0);
    $status = trim($_GET['status'] ?? '');
    $limit  = min(100, intval($_GET['limit'] ?? 50));
    $offset = max(0, intval($_GET['offset'] ?? 0));

    $sql = "SELECT * FROM orders WHERE 1=1";
    $params = [];
    if ($userId > 0) {
        $sql .= " AND user_id = ?";
        $params[] = $userId;
    }
    if ($status && $status !== 'all') {
        $sql .= " AND status = ?";
        $params[] = $status;
    }
    $sql .= " ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($orders as &$o) {
        $o['items'] = json_decode($o['items'] ?? '[]', true) ?: [];
    }
    response(0, 'success', $orders);

} catch (Exception $e) {
    response(1, '操作失败：' . $e->getMessage());
}
