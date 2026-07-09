<?php
/**
 * 商品接口
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

    // 拼接完整 URL 的函数
    $baseUrl = (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')
        ? 'https://' . ($_SERVER['HTTP_HOST'] ?? 'taozhi.433345.xyz')
        : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'taozhi.433345.xyz');

    // 单个商品
    if (!empty($_GET['id'])) {
        $id = intval($_GET['id']);
        $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        if ($p) {
            // 主图转完整 URL
            if (!empty($p['image']) && strpos($p['image'], 'http') !== 0) {
                $p['image'] = $baseUrl . '/' . ltrim($p['image'], '/');
            }
            // 详情图数组转完整 URL
            if (!empty($p['description'])) {
                $desc = json_decode($p['description'], true);
                if (is_array($desc)) {
                    foreach ($desc as &$d) {
                        if (!empty($d) && strpos($d, 'http') !== 0) {
                            $d = $baseUrl . '/' . ltrim($d, '/');
                        }
                    }
                    unset($d);
                    $p['description'] = json_encode($desc);
                }
            }
            response(0, 'success', $p);
        } else {
            response(1, '商品不存在');
        }
        exit;
    }

    // 关键词搜索
    if (!empty($_GET['keyword'])) {
        $kw = trim($_GET['keyword']);
        $stmt = $db->prepare("SELECT * FROM products WHERE 
            name LIKE ? OR spec LIKE ? OR category_l1 LIKE ? OR category_l2 LIKE ? OR sku LIKE ? OR barcode LIKE ?
            ORDER BY stock DESC, id
        ");
        $like = "%{$kw}%";
        $stmt->execute([$like, $like, $like, $like, $like, $like]);
        $list = $stmt->fetchAll();
        foreach ($list as &$p) {
            if (!empty($p['image']) && strpos($p['image'], 'http') !== 0) {
                $p['image'] = $baseUrl . '/' . ltrim($p['image'], '/');
            }
        }
        unset($p);
        response(0, 'success', $list);
        exit;
    }

    // 按分类过滤
    $categoryId = intval($_GET['category_id'] ?? 0);
    $categoryL1 = trim($_GET['category_l1'] ?? '');
    $categoryL2 = trim($_GET['category_l2'] ?? '');
    $limit = intval($_GET['limit'] ?? 0);

    $where = ['status = 1'];
    $params = [];

    if ($categoryId > 0) {
        $where[] = "(category_l2_id = ? OR category_l1_id = ?)";
        $params[] = $categoryId;
        $params[] = $categoryId;
    } elseif ($categoryL2) {
        $where[] = "category_l2 = ?";
        $params[] = $categoryL2;
    } elseif ($categoryL1) {
        $where[] = "category_l1 = ?";
        $params[] = $categoryL1;
    }

    $sql = "SELECT * FROM products WHERE " . implode(' AND ', $where) 
         . " ORDER BY (stock > 0) DESC, (sale_price > 0 AND sale_price < price) DESC, id";
    
    if ($limit > 0) {
        $sql .= " LIMIT " . intval($limit);
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $list = $stmt->fetchAll();
    foreach ($list as &$p) {
        if (!empty($p['image']) && strpos($p['image'], 'http') !== 0) {
            $p['image'] = $baseUrl . '/' . ltrim($p['image'], '/');
        }
    }
    unset($p);
    response(0, 'success', $list);

} catch (Exception $e) {
    response(1, '查询失败: ' . $e->getMessage());
}
