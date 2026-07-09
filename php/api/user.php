<?php
/**
 * 用户接口 - 微信授权登录
 */
require_once __DIR__ . '/db.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Origin, Content-Type, Accept, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

function response($code = 0, $msg = '', $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

// 兼容 JSON 和表单格式的 POST 解析（微信小程序默认发 JSON，PHP $_POST 只能解析表单）
function parsePost() {
    if (!empty($_POST)) return $_POST;
    $input = file_get_contents('php://input');
    if (!$input) return [];
    $json = json_decode($input, true);
    if (is_array($json)) return $json;
    parse_str($input, $data);
    return is_array($data) ? $data : [];
}
$_POST = array_merge($_POST, parsePost());

$action = $_GET['action'] ?? '';

try {
    $db = getDB();

    // 自动建表（确保 users 表存在）
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            openid VARCHAR(64) NOT NULL DEFAULT '' UNIQUE,
            nickname VARCHAR(100) NOT NULL DEFAULT '',
            avatar VARCHAR(500) NOT NULL DEFAULT '',
            phone VARCHAR(20) NOT NULL DEFAULT '',
            balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            points INT UNSIGNED NOT NULL DEFAULT 0,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_openid (openid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        // 建表失败不影响，后续操作会再次报错
    }

    if ($action === 'login') {
        // 微信授权登录
        $code = $_POST['code'] ?? '';
        $nickname = $_POST['nickname'] ?? '';
        $avatar = $_POST['avatar'] ?? '';
        $phone = $_POST['phone'] ?? '';

        if (!$code) {
            response(1, '登录参数错误');
        }

        // 客户端传入的设备标识（未配置 appid/secret 时使用）
        $localId = $_POST['local_id'] ?? '';

        // 尝试通过微信 code2session 换取 openid
        $appCfg = @include __DIR__ . '/../wechat_config.php';
        $openid = '';

        if ($appCfg && !empty($appCfg['appid']) && !empty($appCfg['secret'])) {
            $url = "https://api.weixin.qq.com/sns/jscode2session?appid={$appCfg['appid']}&secret={$appCfg['secret']}&js_code={$code}&grant_type=authorization_code";
            $ctx = stream_context_create(['http' => ['timeout' => 5]]);
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp) {
                $wxData = json_decode($resp, true);
                if (!empty($wxData['openid'])) {
                    $openid = $wxData['openid'];
                }
            }
        }

        // 未配置 appid/secret 时用客户端传的 local_id
        if (!$openid) {
            if (!$localId) {
                response(1, '登录失败，请配置微信 AppID/Secret');
            }
            $openid = 'local_' . $localId;
        }

        // 查找或创建用户
        $stmt = $db->prepare("SELECT * FROM users WHERE openid = ?");
        $stmt->execute([$openid]);
        $user = $stmt->fetch();

        if ($user) {
            // 只更新前端传入的非空字段，避免用空值覆盖已有头像/昵称/手机号
            $sets = [];
            $params = [];
            if ($nickname !== '') { $sets[] = 'nickname = ?'; $params[] = $nickname; }
            if ($avatar !== '')   { $sets[] = 'avatar = ?';   $params[] = $avatar; }
            if ($phone !== '')    { $sets[] = 'phone = ?';    $params[] = $phone; }
            if (!empty($sets)) {
                $sets[] = 'updated_at = NOW()';
                $params[] = $openid;
                $stmt = $db->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE openid = ?");
                $stmt->execute($params);
            }
            // 重新读取最新用户数据，确保返回真实头像
            $stmt = $db->prepare("SELECT * FROM users WHERE openid = ?");
            $stmt->execute([$openid]);
            $user = $stmt->fetch();
        } else {
            // 创建新用户
            $stmt = $db->prepare("INSERT INTO users (openid, nickname, avatar, phone, balance, points, created_at, updated_at) VALUES (?, ?, ?, ?, 0, 0, NOW(), NOW())");
            $stmt->execute([$openid, $nickname, $avatar, $phone]);
            $userId = $db->lastInsertId();
            $user = [
                'id' => $userId,
                'openid' => $openid,
                'nickname' => $nickname,
                'avatar' => $avatar,
                'phone' => $phone,
                'balance' => '0.00',
                'points' => 0
            ];
        }

        response(0, 'success', [
            'id' => (int)$user['id'],
            'openid' => $user['openid'] ?? '',
            'nickname' => $user['nickname'] ?: '微信用户',
            'avatar' => $user['avatar'] ?: '',
            'phone' => $user['phone'] ?: ''
        ]);
    }

    elseif ($action === 'assets') {
        $userId = $_GET['user_id'] ?? 0;
        if (!$userId) response(1, '参数错误');

        $stmt = $db->prepare("SELECT balance, points FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        response(0, 'success', [
            'balance' => $user ? ($user['balance'] ?? '0.00') : '0.00',
            'points' => $user ? (int)($user['points'] ?? 0) : 0
        ]);
    }

    elseif ($action === 'update') {
        $userId = $_POST['user_id'] ?? 0;
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';

        if (!$userId || !in_array($field, ['nickname', 'phone', 'avatar'])) {
            response(1, '参数错误');
        }

        $stmt = $db->prepare("UPDATE users SET $field = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$value, $userId]);
        response(0, 'success');
    }

    elseif ($action === 'update_avatar') {
        $userId = intval($_POST['user_id'] ?? 0);
        if (!$userId) response(1, '参数错误');
        if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            response(1, '未收到头像文件');
        }

        $file = $_FILES['avatar'];
        $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt)) {
            response(1, '头像格式不支持');
        }
        // 简单 MIME 校验（优先用 fileinfo，缺失则用浏览器上报类型兜底）
        $mime = $file['type'] ?? '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }
        $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $allowedMime)) {
            response(1, '头像文件类型异常');
        }

        $uploadDir = __DIR__ . '/../uploads/avatars/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'png';
        $filename = 'avatar_' . $userId . '_' . time() . '.' . $ext;
        $target = $uploadDir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            response(1, '头像保存失败');
        }

        $avatarUrl = '/uploads/avatars/' . $filename;
        $stmt = $db->prepare("UPDATE users SET avatar = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$avatarUrl, $userId]);
        response(0, 'success', ['avatar' => $avatarUrl]);
    }

    else {
        response(1, '未知操作');
    }

} catch (Exception $e) {
    response(1, '系统错误: ' . $e->getMessage());
}
