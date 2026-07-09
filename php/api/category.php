<?php
/**
 * 分类接口
 */
require_once __DIR__ . '/db.php';

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

function response($code = 0, $msg = '', $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = getDB();

    // 确保 status 字段存在（兼容旧表）
    try {
        $db->exec("ALTER TABLE categories ADD COLUMN status TINYINT NOT NULL DEFAULT 1");
    } catch (Exception $e) {
        // 字段已存在，忽略
    }

    $stmt = $db->query("SELECT * FROM categories WHERE status = 1 ORDER BY sort, id");
    $categories = $stmt->fetchAll();

    // 去重：按 id 去重，防止脏数据导致重复
    $seen = [];
    $unique = [];
    foreach ($categories as $cat) {
        $cid = intval($cat['id']);
        if (!isset($seen[$cid])) {
            $seen[$cid] = true;
            $unique[] = $cat;
        }
    }

    response(0, 'success', $unique);
} catch (Exception $e) {
    response(1, '查询失败');
}
