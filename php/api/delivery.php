<?php
/**
 * 达达配送对接模块
 * 封装达达开放平台 API：下单、查询、取消、完成
 *
 * 使用方式：
 *   require __DIR__ . '/delivery.php';
 *   $result = createDadaOrder($orderNo, $storeId, $receiverInfo, $totalPrice);
 */

require_once __DIR__ . '/../wechat_config.php';
require_once __DIR__ . '/db.php';

$config = require __DIR__ . '/../wechat_config.php';

// ===== 环境配置 =====
$isSandbox = $config['sandbox'] ?? true;
$dadaHost  = $isSandbox ? 'http://newopen.qa.imdada.cn' : 'https://newopen.imdada.cn';
$appKey    = $config['dada_app_key'] ?? '';
$appSecret = $config['dada_app_secret'] ?? '';
$sourceId  = $config['dada_source_id'] ?? '';
$storeNo   = $config['dada_store_no'] ?? '';
$cityCode  = $config['dada_city_code'] ?? '010';
$callback  = $config['dada_callback'] ?? '';

/**
 * 生成达达 API 签名
 */
function dadaSign($appKey, $appSecret, $body) {
    $data = [
        'app_key'   => $appKey,
        'body'      => $body,
        'format'    => 'json',
        'source_id' => '',
        'timestamp' => time(),
        'v'         => '1.0',
    ];
    ksort($data);
    $concat = '';
    foreach ($data as $k => $v) {
        $concat .= $k . $v;
    }
    $sign = strtoupper(md5($appSecret . $concat . $appSecret));
    $data['signature'] = $sign;
    return $data;
}

/**
 * 调用达达 API
 */
function dadaRequest($endpoint, $body) {
    global $dadaHost, $appKey, $appSecret;

    $signData = dadaSign($appKey, $appSecret, $body);
    $postData = json_encode($signData, JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $dadaHost . $endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=utf-8']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("[dada] curl error: $error");
        return ['code' => -1, 'msg' => "网络错误: $error"];
    }

    $result = json_decode($response, true);
    return $result ?: ['code' => -1, 'msg' => '解析失败', 'raw' => $response];
}

/**
 * 获取门店信息（取货地址、电话）
 */
function getStoreInfo($db) {
    $stmt = $db->query("SELECT * FROM store_single WHERE id = 1");
    $store = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$store) {
        // 如果 store_single 表不存在，尝试从 delivery_zones 反查
        return null;
    }
    return $store;
}

/**
 * 创建达达配送订单
 *
 * @param PDO $db 数据库连接
 * @param string $orderNo 你的订单号（如 TZ2026070812345678）
 * @param string $storeId 门店ID（从 orders 表取 store_id）
 * @param string $receiverName 收货人姓名
 * @param string $receiverAddress 收货地址
 * @param float $receiverLat 收货纬度
 * @param float $receiverLng 收货经度
 * @param string $receiverPhone 收货人电话
 * @param float $totalPrice 商品价格
 * @return array ['success'=>true/false, 'dada_order_id'=>'...', 'error'=>'...']
 */
function createDadaOrder($db, $orderNo, $receiverName, $receiverAddress,
                         $receiverLat, $receiverLng, $receiverPhone, $totalPrice) {
    global $storeNo, $cityCode, $callback;

    // 获取门店信息（取货地址）
    $store = getStoreInfo($db);
    if (!$store) {
        return ['success' => false, 'error' => '门店信息不存在'];
    }

    // 组装 body
    $body = json_encode([
        'shop_no'         => $storeNo,
        'origin_id'       => $orderNo,
        'cargo_price'     => round($totalPrice, 2),
        'is_prepay'       => 1,       // 1=商家预付，0=用户预付
        'receiver_name'   => $receiverName,
        'receiver_address'=> $receiverAddress,
        'receiver_lat'    => sprintf('%.6f', $receiverLat),
        'receiver_lng'    => sprintf('%.6f', $receiverLng),
        'receiver_phone'  => $receiverPhone,
        'callback'        => $callback,
        'cargo_weight'    => 1,        // 默认 1kg
    ], JSON_UNESCAPED_UNICODE);

    $result = dadaRequest('/api/order/addOrder', $body);

    if (isset($result['code']) && $result['code'] == 0) {
        return [
            'success'     => true,
            'dada_order_id' => $result['client_id'] ?? '',
            'message'     => '下单成功',
        ];
    } else {
        return [
            'success' => false,
            'error'   => $result['msg'] ?? $result['message'] ?? json_encode($result, JSON_UNESCAPED_UNICODE),
        ];
    }
}

/**
 * 查询达达订单（骑手信息、配送状态）
 *
 * @param string $dadaOrderId 达达订单号
 * @return array
 */
function queryDadaOrder($dadaOrderId) {
    global $storeNo;

    $body = json_encode([
        'shop_no'   => $storeNo,
        'client_id' => $dadaOrderId,
    ], JSON_UNESCAPED_UNICODE);

    $result = dadaRequest('/api/order/fetch', $body);
    return $result;
}

/**
 * 取消达达配送
 *
 * @param string $dadaOrderId 达达订单号
 * @param string $reason 取消原因
 * @return array
 */
function cancelDadaOrder($dadaOrderId, $reason = '商家主动取消') {
    global $storeNo;

    $body = json_encode([
        'shop_no'    => $storeNo,
        'client_id'  => $dadaOrderId,
        'cancel_reason' => $reason,
    ], JSON_UNESCAPED_UNICODE);

    $result = dadaRequest('/api/order/cancel', $body);
    return $result;
}

/**
 * 确认送达
 *
 * @param string $dadaOrderId 达达订单号
 * @return array
 */
function finishDadaOrder($dadaOrderId) {
    global $storeNo;

    $body = json_encode([
        'shop_no'   => $storeNo,
        'client_id' => $dadaOrderId,
    ], JSON_UNESCAPED_UNICODE);

    $result = dadaRequest('/api/order/finish', $body);
    return $result;
}

/**
 * 查询配送费（用于结算页预估）
 *
 * @param float $receiverLat 收货纬度
 * @param float $receiverLng 收货经度
 * @param float $totalPrice 商品总价
 * @return array
 */
function queryDeliveryFee($receiverLat, $receiverLng, $totalPrice) {
    global $storeNo;

    $body = json_encode([
        'shop_no'       => $storeNo,
        'receiver_lat'  => sprintf('%.6f', $receiverLat),
        'receiver_lng'  => sprintf('%.6f', $receiverLng),
        'cargo_price'   => round($totalPrice, 2),
    ], JSON_UNESCAPED_UNICODE);

    $result = dadaRequest('/api/order/queryDeliverFee', $body);
    return $result;
}
