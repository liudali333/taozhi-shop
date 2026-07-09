<?php
/**
 * 微信支付回调通知
 * 微信会向 notify_url POST 支付结果
 * 验签成功后更新订单状态，返回 200 + 成功/失败
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../wechat_config.php';

header('Content-Type: application/json; charset=utf-8');

// 读取原始数据
$raw = file_get_contents('php://input');
if (!$raw) {
    http_response_code(400);
    echo json_encode(['code' => 'FAIL', 'message' => 'no data']);
    exit;
}

// 解析回调 header
$headers = getallheaders();
$signature = $headers['Wechatpay-Signature'] ?? '';
$timestamp = $headers['Wechatpay-Timestamp'] ?? '';
$nonce     = $headers['Wechatpay-Nonce'] ?? '';
$serial    = $headers['Wechatpay-Serial'] ?? '';

// 验签
$config = require __DIR__ . '/../wechat_config.php';

// 拼接待验签字符串
$message = $timestamp . "\n" . $nonce . "\n" . $raw . "\n";

// 读取微信平台证书（公钥）
// 注意：实际生产中需要下载微信平台证书并缓存，这里简化处理
$publicKeyPath = $config['public_key_path'] ?? '';
if (!$publicKeyPath || !file_exists($publicKeyPath)) {
    http_response_code(500);
    echo json_encode(['code' => 'FAIL', 'message' => '公钥证书未配置']);
    exit;
}

$publicKey = file_get_contents($publicKeyPath);
$verifyOk = openssl_verify(
    $message,
    base64_decode($signature),
    $publicKey,
    OPENSSL_ALGO_SHA256
);

if ($verifyOk !== 1) {
    http_response_code(401);
    echo json_encode(['code' => 'FAIL', 'message' => '签名验证失败']);
    exit;
}

// 解密回调数据
$resource = json_decode($raw, true);
$ciphertext = $resource['resource']['ciphertext'] ?? '';
if (!$ciphertext) {
    http_response_code(400);
    echo json_encode(['code' => 'FAIL', 'message' => 'no ciphertext']);
    exit;
}

// AEAD-AES-256-GCM 解密
$associatedData = $resource['resource']['associated_data'] ?? '';
$nonceStr       = $resource['resource']['nonce'] ?? '';

$decrypted = '';
$tagLen = 16;
$cipherLen = strlen($ciphertext) - $tagLen;
$cipher = substr($ciphertext, 0, $cipherLen);
$tag    = substr($ciphertext, $cipherLen);

$ok = openssl_decrypt(
    $cipher,
    'aes-256-gcm',
    $config['api_v3_key'],
    OPENSSL_RAW_DATA,
    $nonceStr,
    $decrypted,
    $tag,
    $associatedData
);

if (!$ok) {
    http_response_code(400);
    echo json_encode(['code' => 'FAIL', 'message' => '解密失败']);
    exit;
}

$event = json_decode($decrypted, true);
$orderNo = $event['out_trade_no'] ?? '';
$tradeState = $event['trade_state'] ?? '';

if ($tradeState === 'SUCCESS' && $orderNo) {
    try {
        $db = getDB();
        $stmt = $db->prepare("UPDATE orders SET 
            status = 'paid', 
            paid_at = FROM_UNIXTIME(?), 
            transaction_id = ? 
            WHERE order_no = ? AND status = 'pending'");
        $stmt->execute([
            $event['success_time'] ? intval($event['success_time']) : time(),
            $event['transaction_id'] ?? '',
            $orderNo
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['code' => 'FAIL', 'message' => 'DB error']);
        exit;
    }
}

http_response_code(200);
echo json_encode(['code' => 'SUCCESS', 'message' => '成功']);
