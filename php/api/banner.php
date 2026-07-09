<?php
/**
 * 轮播图接口
 * 自动把数据库中的相对路径（/uploads/xxx.jpg）转为完整 URL
 */
require_once __DIR__ . '/db.php';

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

function response($code = 0, $msg = '', $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

// 自动拼接完整 URL，避免小程序端需要再处理路径
function toFullUrl($path) {
    if (!$path) return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'taozhi.433345.xyz';
    if ($path[0] !== '/') $path = '/' . $path;
    return $proto . '://' . $host . $path;
}

try {
    $db = getDB();
    $stmt = $db->query("SELECT id, title, image, link, sort, status FROM banners WHERE status = 1 ORDER BY sort, id");
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        if ($row['image'] && strpos($row['image'], 'data:') === 0) {
            $row['image'] = '';
        } else {
            $row['image'] = toFullUrl($row['image']);
        }
    }
    unset($row);
    response(0, 'success', $rows);
} catch (Exception $e) {
    response(0, 'success', []);
}
