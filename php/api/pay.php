<?php
/**
 * 微信支付接口（V3 API）
 *
 * 路由：
 *   ?action=prepay  - 调起支付（创建预付单 + 返回 JSAPI 调起参数）
 *   ?action=query   - 查询订单
 *   ?action=close   - 关闭订单
 *
 * 前端调用：
 *   POST /api/pay.php?action=prepay  { order_no, openid }
 *   返回 { code:0, data:{ timeStamp, nonceStr, package, signType, paySign } }
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../wechat_config.php';

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

$action = $_GET['action'] ?? '';

// ============= 工具函数 =============

/**
 * 生成签名（V3 SHA256withRSA）
 * $method = 'GET'|'POST'|'PUT'|'DELETE'
 * $url    = '/v3/pay/transactions/jsapi'
 * $body   = 请求体（POST 时为 JSON 字符串，GET 时为 ''）
 */
function wechatPaySign($method, $url, $body, $config) {
    // 如果 private_key 是文件路径，则读取并转为私钥资源
    if (is_string($config['private_key']) && file_exists($config['private_key'])) {
        $config['private_key'] = openssl_pkey_get_private(file_get_contents($config['private_key']));
    }
    $timestamp = (string)time();
    $nonceStr  = bin2hex(random_bytes(16));
    $message   = $method . "\n" . $url . "\n" . $timestamp . "\n" . $nonceStr . "\n" . $body . "\n";

    openssl_sign(
        $message,
        $rawSign,
        $config['private_key'],
        'sha256WithRSAEncryption'
    );
    $sign = base64_encode($rawSign);

    $token = sprintf(
        'mchid="%s",nonce_str="%s",timestamp="%s",serial_no="%s",signature="%s"',
        $config['mch_id'],
        $nonceStr,
        $timestamp,
        $config['serial_no'],
        $sign
    );
    return ['Authorization' => 'WECHATPAY2-SHA256-RSA2048 ' . $token, 'timestamp' => $timestamp, 'nonceStr' => $nonceStr];
}

/**
 * HTTP 请求（带 Authorization）
 */
function wechatPayRequest($method, $url, $body, $config) {
    $sign = wechatPaySign($method, $url, $body, $config);
    $fullUrl = 'https://api.mch.weixin.qq.com' . $url;

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: taozhi-miniprogram/1.0',
        'Authorization: ' . $sign['Authorization'],
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $fullUrl,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    }

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'err' => $err, 'code' => $httpCode];
    }
    return ['ok' => true, 'code' => $httpCode, 'body' => $resp, 'json' => json_decode($resp, true)];
}

/**
 * 生成前端调起支付的 paySign
 * 签名字符串：appId\ntimeStamp\nnonceStr\npackage\n
 */
function makePaySign($prepayId, $config) {
    // 如果 private_key 是文件路径，则读取并转为私钥资源
    if (is_string($config['private_key']) && file_exists($config['private_key'])) {
        $config['private_key'] = openssl_pkey_get_private(file_get_contents($config['private_key']));
    }
    $appId     = $config['appid'];
    $timeStamp = (string)time();
    $nonceStr  = bin2hex(random_bytes(16));
    $package   = 'prepay_id=' . $prepayId;

    $message = $appId . "\n" . $timeStamp . "\n" . $nonceStr . "\n" . $package . "\n";

    openssl_sign(
        $message,
        $rawSign,
        $config['private_key'],
        'sha256WithRSAEncryption'
    );
    $paySign = base64_encode($rawSign);

    return [
        'appId'     => $appId,
        'timeStamp' => $timeStamp,
        'nonceStr'  => $nonceStr,
        'package'   => $package,
        'signType'  => 'RSA',
        'paySign'   => $paySign,
    ];
}

/**
 * 读取微信小程序配置
 */
function wechat_config() {
    static $cfg = null;
    if ($cfg === null) $cfg = require __DIR__ . '/../wechat_config.php';
    return $cfg;
}

// ============= 路由 =============

if ($action === 'prepay') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') response(1, '请用 POST');

    $input = parsePost();
    $orderNo = trim($input['order_no'] ?? '');
    $openid  = trim($input['openid'] ?? '');

    if (!$orderNo) response(1, '订单号不能为空');
    if (!$openid)  response(1, 'openid 不能为空');

    $config = require __DIR__ . '/../wechat_config.php';
    if (empty($config['mch_id']) || $config['mch_id'] === '你的商户号') {
        response(1, '微信支付未配置：请填写 wechat_config.php');
    }

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM orders WHERE order_no = ?");
        $stmt->execute([$orderNo]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) response(1, '订单不存在');
        if ($order['status'] === 'paid') response(1, '订单已支付');

        $total = intval(round(floatval($order['final_price']) * 100)); // 单位：分

        $url = '/v3/pay/transactions/jsapi';
        $body = json_encode([
            'appid'        => wechat_config()['appid'],
            'mchid'        => $config['mch_id'],
            'description'  => '桃之商城-' . $orderNo,
            'out_trade_no' => $orderNo,
            'notify_url'   => $config['notify_url'],
            'amount'       => [
                'total'    => $total,
                'currency' => 'CNY',
            ],
            'payer' => [
                'openid' => $openid,
            ],
        ], JSON_UNESCAPED_UNICODE);

        $resp = wechatPayRequest('POST', $url, $body, $config);

        if (!$resp['ok'] || $resp['code'] !== 200) {
            response(1, '微信下单失败：HTTP ' . $resp['code'] . ' / ' . ($resp['body'] ?? $resp['err']));
        }

        $prepayId = $resp['json']['prepay_id'] ?? '';
        if (!$prepayId) {
            response(1, '微信返回数据异常：' . ($resp['body'] ?? ''));
        }

        // 存一下 prepay_id，方便后续查询
        $db->prepare("UPDATE orders SET prepay_id = ? WHERE order_no = ?")
            ->execute([$prepayId, $orderNo]);

        // 生成前端调起支付参数
        $jsapi = makePaySign($prepayId, $config);
        response(0, 'ok', $jsapi);

    } catch (Exception $e) {
        response(1, '系统异常：' . $e->getMessage());
    }
}

if ($action === 'query') {
    $orderNo = trim($_GET['order_no'] ?? '');
    if (!$orderNo) response(1, '订单号不能为空');

    $config = require __DIR__ . '/../wechat_config.php';
    // V3 查询接口需要带上 mchid 参数
    $url = '/v3/pay/transactions/out-trade-no/' . urlencode($orderNo) . '?mchid=' . $config['mch_id'];
    $resp = wechatPayRequest('GET', $url, '', $config);

    // 调试日志
    error_log("[PAY_QUERY] order_no={$orderNo}, resp_code={$resp['code']}, body=" . substr($resp['body'] ?? '', 0, 500));

    if ($resp['ok'] && $resp['code'] === 200) {
        $data = $resp['json'];
        // 如果支付成功，自动更新订单状态
        if (isset($data['trade_state']) && $data['trade_state'] === 'SUCCESS') {
            try {
                $db = getDB();
                $stmt = $db->prepare("UPDATE orders SET 
                    status = 'paid', 
                    paid_at = NOW(), 
                    transaction_id = ?, 
                    delivery_status = 'accepted' 
                    WHERE order_no = ? AND status = 'pending'");
                $stmt->execute([
                    $data['transaction_id'] ?? '',
                    $orderNo
                ]);
                $affected = $stmt->rowCount();
                error_log("[PAY_QUERY] order updated: order_no={$orderNo}, affected={$affected}");
            } catch (Exception $e) {
                error_log("[PAY_QUERY] DB error: " . $e->getMessage());
            }
        }
        response(0, 'ok', $data);
    }
    response(1, '查询失败：HTTP ' . $resp['code'] . ' / ' . ($resp['body'] ?? ''));
}

if ($action === 'close') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') response(1, '请用 POST');
    $input = parsePost();
    $orderNo = trim($input['order_no'] ?? '');
    if (!$orderNo) response(1, '订单号不能为空');

    $config = require __DIR__ . '/../wechat_config.php';
    $url = '/v3/pay/transactions/out-trade-no/' . urlencode($orderNo) . '/close';
    $body = json_encode(['mchid' => $config['mch_id']]);
    $resp = wechatPayRequest('POST', $url, $body, $config);

    if ($resp['ok'] && $resp['code'] === 204) {
        try {
            $db = getDB();
            $db->prepare("UPDATE orders SET status = 'cancelled' WHERE order_no = ?")
                ->execute([$orderNo]);
        } catch (Exception $e) {}
        response(0, '订单已关闭');
    }
    response(1, '关闭失败：HTTP ' . $resp['code'] . ' / ' . ($resp['body'] ?? ''));
}

response(1, '未知 action');
