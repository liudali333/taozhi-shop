<?php
/**
 * 收货地址接口
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

// 兼容 JSON 和表单格式的 POST 解析
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

    // 确保地址表存在
    $db->exec("CREATE TABLE IF NOT EXISTS user_addresses (
        id VARCHAR(64) PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        name VARCHAR(100) NOT NULL DEFAULT '',
        phone VARCHAR(20) NOT NULL DEFAULT '',
        region VARCHAR(200) NOT NULL DEFAULT '',
        detail VARCHAR(500) NOT NULL DEFAULT '',
        lat DECIMAL(10,7) NOT NULL DEFAULT 0,
        lng DECIMAL(10,7) NOT NULL DEFAULT 0,
        address_name VARCHAR(200) NOT NULL DEFAULT '',
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($action === 'list') {
        $userId = intval($_GET['user_id'] ?? 0);
        if (!$userId) response(1, '参数错误');

        $stmt = $db->prepare("SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, created_at DESC");
        $stmt->execute([$userId]);
        $list = $stmt->fetchAll();

        response(0, 'success', $list);
    }

    elseif ($action === 'sync') {
        $userId = intval($_POST['user_id'] ?? 0);
        $addresses = $_POST['addresses'] ?? '[]';
        if (!$userId) response(1, '参数错误');

        $list = json_decode($addresses, true);
        if (!is_array($list)) response(1, '数据格式错误');

        // 删除旧地址
        $stmt = $db->prepare("DELETE FROM user_addresses WHERE user_id = ?");
        $stmt->execute([$userId]);

        // 批量插入
        $insertStmt = $db->prepare("INSERT INTO user_addresses (id, user_id, name, phone, region, detail, lat, lng, address_name, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        foreach ($list as $addr) {
            $insertStmt->execute([
                $addr['id'] ?? uniqid(),
                $userId,
                $addr['name'] ?? '',
                $addr['phone'] ?? '',
                $addr['region'] ?? '',
                $addr['detail'] ?? '',
                floatval($addr['lat'] ?? 0),
                floatval($addr['lng'] ?? 0),
                $addr['addressName'] ?? '',
                $addr['isDefault'] ? 1 : 0
            ]);
        }

        response(0, '同步成功');
    }

    else {
        response(1, '未知操作');
    }

} catch (Exception $e) {
    response(1, '系统错误: ' . $e->getMessage());
}
