<?php
/**
 * 分享配置接口
 * - GET  ?page=home   获取单页分享配置（小程序端调用）
 * - GET  ?action=list 获取全部分享配置（后台用）
 */
require_once __DIR__ . '/db.php';

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

function response($code = 0, $msg = '', $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

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
    $action = $_GET['action'] ?? '';
    $page   = $_GET['page'] ?? '';

    if ($action === 'list') {
        $rows = $db->query("SELECT * FROM share_configs ORDER BY sort, id")->fetchAll();
        foreach ($rows as &$r) {
            $r['share_img'] = toFullUrl($r['share_img']);
        }
        unset($r);
        response(0, 'success', $rows);
    }

    if ($page) {
        $stmt = $db->prepare("SELECT page_key, page_name, share_title, share_img, status FROM share_configs WHERE page_key = ? AND status = 1");
        $stmt->execute([$page]);
        $row = $stmt->fetch();
        if (!$row) response(1, '未找到分享配置', []);
        $row['share_img'] = toFullUrl($row['share_img']);
        response(0, 'success', $row);
    }

    response(1, '参数错误');
} catch (Exception $e) {
    response(1, '服务器错误: ' . $e->getMessage());
}
