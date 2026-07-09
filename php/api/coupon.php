<?php
/**
 * 优惠券接口
 */
require_once __DIR__ . '/db.php';

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

function response($code = 0, $msg = '', $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function parsePost() {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if ($json) return $json;
    return $_POST;
}

/**
 * 发送订阅消息（领取优惠券后提醒）
 * 需要在微信公众平台 → 订阅消息 → 公共模板库 申请「领取成功」类模板
 *
 * @param string $openid       用户 openid
 * @param string $templateId   订阅消息模板 ID（在公众平台查看）
 * @param array  $data         模板字段，例如 ['thing1' => ['value' => '名称'], 'date2' => ['value' => '2026-12-31']]
 * @return bool|string         成功 true，失败返回错误信息
 */
function sendSubscribeMessage($openid, $templateId, $data) {
    if (!$templateId || !$openid) return false;

    $wxConfig = require __DIR__ . '/../wechat_config.php';
    $appid  = $wxConfig['appid'];
    $secret = $wxConfig['secret'];

    // 获取 access_token
    $ch = curl_init("https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={$appid}&secret={$secret}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $tokenResp = curl_exec($ch);
    curl_close($ch);

    $tokenData = json_decode($tokenResp, true);
    if (empty($tokenData['access_token'])) return 'get token failed';
    $accessToken = $tokenData['access_token'];

    // 发送订阅消息
    $url = "https://api.weixin.qq.com/cgi-bin/message/subscribe/send?access_token={$accessToken}";
    $post = json_encode([
        'touser'      => $openid,
        'template_id' => $templateId,
        'data'        => $data,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $resp = curl_exec($ch);
    curl_close($ch);

    $respData = json_decode($resp, true);
    if (isset($respData['errcode']) && $respData['errcode'] === 0) {
        return true;
    }
    return $resp;
}

try {
    $db = getDB();
    $action = $_GET['action'] ?? '';

    // ========== 用户优惠券数量 ==========
    if ($action === 'count') {
        $userId = intval($_GET['user_id'] ?? 0);
        if (!$userId) response(0, 'success', 0);

        $now = date('Y-m-d');
        $stmt = $db->prepare("SELECT COUNT(*) FROM user_coupons uc JOIN coupons c ON uc.coupon_id = c.id WHERE uc.user_id = ? AND uc.status = 'usable' AND c.end_time >= ?");
        $stmt->execute([$userId, $now]);
        response(0, 'success', intval($stmt->fetchColumn()));
    }

    // ========== 用户优惠券列表 ==========
    elseif ($action === 'my_list') {
        $userId = intval($_GET['user_id'] ?? 0);
        $status = $_GET['status'] ?? 'usable'; // usable/used/expired
        if (!$userId) response(0, 'success', []);

        $now = date('Y-m-d');

        if ($status === 'usable') {
            $stmt = $db->prepare("SELECT uc.id as uc_id, c.*, 'usable' as status FROM user_coupons uc JOIN coupons c ON uc.coupon_id = c.id WHERE uc.user_id = ? AND uc.status = 'usable' AND c.end_time >= ?");
            $stmt->execute([$userId, $now]);
        } elseif ($status === 'used') {
            $stmt = $db->prepare("SELECT uc.id as uc_id, c.*, 'used' as status FROM user_coupons uc JOIN coupons c ON uc.coupon_id = c.id WHERE uc.user_id = ? AND uc.status = 'used'");
            $stmt->execute([$userId]);
        } else { // expired
            $stmt = $db->prepare("SELECT uc.id as uc_id, c.*, 'expired' as status FROM user_coupons uc JOIN coupons c ON uc.coupon_id = c.id WHERE uc.user_id = ? AND (uc.status = 'used' OR c.end_time < ?)");
            $stmt->execute([$userId, $now]);
        }

        response(0, 'success', $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ========== 优惠券详情（领券页用） ==========
    elseif ($action === 'detail') {
        $id = intval($_GET['id'] ?? 0);
        if (!$id) response(400, '缺少优惠券ID');

        $stmt = $db->prepare("SELECT * FROM coupons WHERE id = ? AND status = 1");
        $stmt->execute([$id]);
        $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$coupon) response(404, '优惠券不存在或已下架');

        response(0, 'success', $coupon);
    }

    // ========== 领取优惠券 ==========
    elseif ($action === 'receive') {
        $post = parsePost();
        $userId = intval($post['user_id'] ?? 0);
        $couponId = intval($post['coupon_id'] ?? 0);

        if (!$userId) response(401, '请先登录');
        if (!$couponId) response(400, '缺少优惠券ID');

        // 检查优惠券是否存在、有效
        $stmt = $db->prepare("SELECT * FROM coupons WHERE id = ? AND status = 1");
        $stmt->execute([$couponId]);
        $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$coupon) response(404, '优惠券不存在或已下架');

        // 检查库存
        if ($coupon['stock'] <= 0) response(400, '优惠券已领完');

        // 检查是否已领取
        $stmt = $db->prepare("SELECT id FROM user_coupons WHERE user_id = ? AND coupon_id = ?");
        $stmt->execute([$userId, $couponId]);
        if ($stmt->fetch()) response(400, '您已领取过此优惠券');

        // 检查有效期
        $now = date('Y-m-d');
        if ($coupon['start_time'] > $now || $coupon['end_time'] < $now) {
            response(400, '优惠券不在有效期内');
        }

        // 领取：插入user_coupons，扣减库存
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO user_coupons (user_id, coupon_id, status, created_at) VALUES (?, ?, 'usable', NOW())")->execute([$userId, $couponId]);
            $db->prepare("UPDATE coupons SET stock = stock - 1 WHERE id = ?")->execute([$couponId]);
            $db->commit();
            $isFromQr = !empty($post['from']) && $post['from'] === 'qr';

            // 发送订阅消息（需要用户事先在小程序内点击过订阅授权）
            $templateId = $wxConfig['coupon_subscribe_tpl'] ?? '';
            if ($templateId) {
                $stmt = $db->prepare("SELECT openid FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $u = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($u && !empty($u['openid'])) {
                    // 对应模板《优惠券到账提醒》（模板ID: mov_hroYwggIBcnKTa43bRLMGNA70gbn2-k-VlLfq9A）
                    // 字段映射：券名称=thing1 / 券类型=thing5 / 券有效期=short_thing3 / 温馨提示=thing4
                    // short_thing3 限制 ≤ 5 个字符，需格式化为 "MM.DD" 短格式
                    $validityStr = date('m.d', strtotime($coupon['end_time'])); // 如 "12.31"
                    $msgData = [
                        'thing1'       => ['value' => mb_substr($coupon['title'], 0, 20)],   // 券名称
                        'thing5'       => ['value' => '满减券'],                              // 券类型（当前只保留满减券）
                        'short_thing3' => ['value' => $validityStr],                          // 券有效期（短文本，限5字符）
                        'thing4'       => ['value' => '您的优惠券已到账，请注意查收'],         // 温馨提示
                    ];
                    @sendSubscribeMessage($u['openid'], $templateId, $msgData);
                }
            }

            response(0, '领取成功', [
                'from_qr'        => $isFromQr,
                'coupon_title'   => $coupon['title'],
                'coupon_value'   => $coupon['value'],
                'coupon_min'     => $coupon['min_amount'],
                'coupon_end'     => $coupon['end_time'],
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            response(500, '领取失败：' . $e->getMessage());
        }
    }

    // ========== 可用优惠券（下单时用） ==========
    elseif ($action === 'available') {
        $userId = intval($_GET['user_id'] ?? 0);
        $amount = floatval($_GET['amount'] ?? 0);
        if (!$userId) response(0, 'success', []);

        $now = date('Y-m-d');
        $stmt = $db->prepare("SELECT uc.id as uc_id, c.* FROM user_coupons uc JOIN coupons c ON uc.coupon_id = c.id WHERE uc.user_id = ? AND uc.status = 'usable' AND c.status = 1 AND c.end_time >= ? AND c.min_amount <= ? ORDER BY c.value DESC");
        $stmt->execute([$userId, $now, $amount]);
        response(0, 'success', $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ========== 后台：获取优惠券二维码 ==========
    elseif ($action === 'get_qrcode') {
        $id = intval($_GET['id'] ?? 0);
        if (!$id) response(400, '缺少优惠券ID');

        $wxConfig = require __DIR__ . '/../wechat_config.php';
        $appid = $wxConfig['appid'];
        $secret = $wxConfig['secret'];

        // 获取 access_token（cURL 方式，更稳定）
        $tokenUrl = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={$appid}&secret={$secret}";
        $ch = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $tokenResp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $tokenData = json_decode($tokenResp, true);
        if (empty($tokenData['access_token'])) {
            http_response_code(502);
            exit('获取access_token失败，请检查服务器网络或AppID/Secret配置');
        }
        $accessToken = $tokenData['access_token'];

        // 生成无限制小程序码
        // scene 格式: id_数字_qr  →  小程序 onLoad 解析后识别「来自二维码 → 自动领取」
        $qrUrl = "https://api.weixin.qq.com/wxa/getwxacodeunlimit?access_token={$accessToken}";
        $postData = json_encode([
            'scene' => 'id_' . $id . '_qr',
            'page' => 'pages/receive-coupon/receive-coupon',
            'width' => 430,
            'check_path' => false   // 跳过 path 校验，发布前可改回 true
        ]);

        $ch = curl_init($qrUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $qrImage = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 返回内容是JSON（出错）而非图片
        $respLen = strlen($qrImage);
        if ($respLen < 100 || strpos($qrImage, '{') === 0) {
            http_response_code(500);
            exit('生成小程序码失败：' . ($respLen < 500 ? $qrImage : substr($qrImage, 0, 200)));
        }

        // 注意：微信 getwxacodeunlimit 实际返回 JPEG 格式，非 PNG
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . $respLen);
        // 允许浏览器缓存一天（微信服务端缓存了access_token，短时间内换 id 重新生成相同的不影响）
        header('Cache-Control: max-age=86400');
        echo $qrImage;
        exit;
    }

    // ========== 后台：推送优惠券给指定用户 ==========
    elseif ($action === 'push') {
        $post = parsePost();
        $userId = intval($post['user_id'] ?? 0);
        $couponId = intval($post['coupon_id'] ?? 0);

        if (!$userId || !$couponId) {
            response(400, '缺少用户ID或优惠券ID');
        }

        // 检查优惠券存在
        $stmt = $db->prepare("SELECT * FROM coupons WHERE id = ? AND status = 1");
        $stmt->execute([$couponId]);
        $coupon = $stmt->fetch();
        if (!$coupon) response(404, '优惠券不存在');

        // 检查用户存在
        $stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) response(404, '用户不存在');

        // 检查是否已领取
        $stmt = $db->prepare("SELECT id FROM user_coupons WHERE user_id = ? AND coupon_id = ?");
        $stmt->execute([$userId, $couponId]);
        if ($stmt->fetch()) response(400, '该用户已领取过此优惠券');

        // 库存检查
        if ($coupon['stock'] <= 0) response(400, '优惠券库存不足');

        // 发放
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO user_coupons (user_id, coupon_id, status, created_at) VALUES (?, ?, 'usable', NOW())")->execute([$userId, $couponId]);
            $db->prepare("UPDATE coupons SET stock = stock - 1 WHERE id = ?")->execute([$couponId]);
            $db->commit();
            response(0, '发放成功');
        } catch (Exception $e) {
            $db->rollBack();
            response(500, '发放失败：' . $e->getMessage());
        }
    }

    // ========== 后台：用户优惠券列表（管理查看） ==========
    elseif ($action === 'user_coupons') {
        $userId = intval($_GET['user_id'] ?? 0);
        if (!$userId) response(400, '缺少用户ID');

        $stmt = $db->prepare("SELECT uc.id as uc_id, uc.status as uc_status, uc.created_at as receive_time, c.* FROM user_coupons uc JOIN coupons c ON uc.coupon_id = c.id WHERE uc.user_id = ? ORDER BY uc.created_at DESC");
        $stmt->execute([$userId]);
        response(0, 'success', $stmt->fetchAll());
    }

    // ========== 默认：返回所有可用优惠券 ==========
    else {
        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare("SELECT * FROM coupons WHERE status = 1 AND (start_time IS NULL OR start_time <= ?) AND (end_time IS NULL OR end_time >= ?) ORDER BY id");
        $stmt->execute([$now, $now]);
        response(0, 'success', $stmt->fetchAll());
    }

} catch (Exception $e) {
    response(500, $e->getMessage());
}
