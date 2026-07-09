<?php
require_once __DIR__ . '/../../wechat_config.php';
require_once __DIR__ . '/../../amap_config.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

if ($action === 'geocode') {
    // 地址 → 坐标
    $address = trim($_GET['address'] ?? '');
    $city    = trim($_GET['city'] ?? '');
    
    if (!$address) {
        echo json_encode(['code' => 400, 'msg' => '缺少地址参数']);
        exit;
    }
    
    $key = AMAP_WEB_KEY;
    $url = "https://restapi.amap.com/v3/geocode/geo?key={$key}&address=" . urlencode($address) . "&city=" . urlencode($city);
    
    $resp = file_get_contents($url);
    $data = json_decode($resp, true);
    
    if ($data['status'] === '1' && !empty($data['geocodes'])) {
        $geo  = $data['geocodes'][0];
        $loc  = explode(',', $geo['location']);
        echo json_encode([
            'code' => 0,
            'data' => [
                'lng'     => $loc[0],
                'lat'     => $loc[1],
                'address' => $geo['formatted_address'] ?? $address
            ]
        ]);
    } else {
        echo json_encode(['code' => 404, 'msg' => '地址解析失败：' . ($data['info'] ?? '未知错误')]);
    }
    
} elseif ($action === 'reverse') {
    // 坐标 → 地址
    $lat = trim($_GET['lat'] ?? '');
    $lng = trim($_GET['lng'] ?? '');
    
    if (!$lat || !$lng) {
        echo json_encode(['code' => 400, 'msg' => '缺少坐标参数']);
        exit;
    }
    
    $key = AMAP_WEB_KEY;
    $url = "https://restapi.amap.com/v3/geocode/regeo?key={$key}&location={$lng},{$lat}&radius=1000&extensions=base";
    
    $resp = file_get_contents($url);
    $data = json_decode($resp, true);
    
    if ($data['status'] === '1' && !empty($data['regeocode'])) {
        $addr = $data['regeocode']['formatted_address'] ?? '';
        echo json_encode([
            'code' => 0,
            'data' => [
                'address' => $addr,
                'lat'     => $lat,
                'lng'     => $lng
            ]
        ]);
    } else {
        echo json_encode(['code' => 404, 'msg' => '逆地理编码失败']);
    }
    
} else {
    echo json_encode(['code' => 400, 'msg' => '未知操作']);
}
