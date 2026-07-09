<?php
/**
 * 门店接口 - 支持多配送区域
 */
require_once __DIR__ . '/db.php';

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

function response($code = 0, $msg = '', $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 计算两点间距离（米）
 */
function distanceMeters($lat1, $lng1, $lat2, $lng2) {
    if ($lat1 == 0 || $lng1 == 0 || $lat2 == 0 || $lng2 == 0) return PHP_INT_MAX;
    $R = 6371000;
    $a = sin(deg2rad($lat2-$lat1)/2)**2
         + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2-$lng1)/2)**2;
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

/**
 * 判断点是否在多边形内（射线法）
 */
function pointInPolygon($lat, $lng, $polygon) {
    $inside = false;
    $n = count($polygon);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = $polygon[$i]['lng']; $yi = $polygon[$i]['lat'];
        $xj = $polygon[$j]['lng']; $yj = $polygon[$j]['lat'];
        if ((($yi > $lat) != ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
            $inside = !$inside;
        }
    }
    return $inside;
}

/**
 * 检查地址是否在配送区域内
 * 返回匹配的配送区域或 null
 */
function checkDeliveryZone($db, $userLat, $userLng, $storeLat, $storeLng) {
    if (!$userLat || !$userLng) return null;
    
    $zones = $db->query("SELECT * FROM delivery_zones WHERE store_id = 1 AND is_active = 1 ORDER BY sort_order, id")
                ->fetchAll();
    
    foreach ($zones as $zone) {
        $inZone = false;
        
        if ($zone['zone_type'] === 'circle') {
            // 圆形区域
            $dist = distanceMeters($userLat, $userLng, $zone['center_lat'], $zone['center_lng']);
            $inZone = $dist <= $zone['radius_meters'];
            
        } elseif ($zone['zone_type'] === 'polygon') {
            // 多边形区域
            $points = json_decode($zone['polygon_points'], true);
            if (is_array($points) && count($points) >= 3) {
                $inZone = pointInPolygon($userLat, $userLng, $points);
            }
            
        } elseif ($zone['zone_type'] === 'distance') {
            // 导航距离模式 - 使用直线距离作为参考
            // 实际导航距离需要调用高德路径规划API，这里用直线距离*1.2估算
            $dist = distanceMeters($userLat, $userLng, $storeLat, $storeLng);
            $inZone = $dist <= $zone['max_distance_meters'];
        }
        
        if ($inZone) {
            return $zone;
        }
    }
    
    return null;
}

try {
    $db = getDB();
    $store = $db->query("SELECT * FROM store_single WHERE id = 1")->fetch();

    if (!$store) {
        $store = [
            'id' => 1, 'name' => '桃之成人用品', 'address' => '', 'phone' => '',
            'lat' => 0, 'lng' => 0,
            'open_time' => '09:00', 'close_time' => '22:00',
            'advance_minutes' => 60, 'notice' => '满59元包配送，保护您的隐私'
        ];
    }

    // 转换时间格式
    $openTime = substr($store['open_time'] ?? '09:00', 0, 5);
    $closeTime = substr($store['close_time'] ?? '22:00', 0, 5);
    $now = date('H:i');
    $store['open_time'] = $openTime;
    $store['close_time'] = $closeTime;
    $store['is_open'] = ($now >= $openTime && $now < $closeTime);
    
    // 确保所有字段都存在（兼容旧数据）
    $store['province'] = $store['province'] ?? '';
    $store['city'] = $store['city'] ?? '';
    $store['district'] = $store['district'] ?? '';

    $action = $_GET['action'] ?? '';

    if ($action === 'check') {
        $userLat = floatval($_GET['lat'] ?? 0);
        $userLng = floatval($_GET['lng'] ?? 0);
        
        // 检查是否在配送区域内
        $matchedZone = checkDeliveryZone($db, $userLat, $userLng, $store['lat'], $store['lng']);
        
        // 计算到门店的直线距离
        $distM = 0;
        if ($userLat && $userLng && $store['lat'] && $store['lng']) {
            $distM = distanceMeters($store['lat'], $store['lng'], $userLat, $userLng);
        }
        
        response(0, 'success', [
            'is_open' => $store['is_open'],
            'can_deliver' => $matchedZone !== null,
            'earliest_order_time' => date('Y-m-d H:i:s', time() + ($store['advance_minutes'] ?? 60) * 60),
            'open_time' => $openTime,
            'close_time' => $closeTime,
            'distance_meters' => round($distM),
            'distance_text' => $distM < 1000 ? round($distM) . 'm' : round($distM / 1000, 1) . 'km',
            'advance_minutes' => $store['advance_minutes'] ?? 60,
            'zone' => $matchedZone ? [
                'id' => $matchedZone['id'],
                'name' => $matchedZone['name'],
                'min_order_amount' => floatval($matchedZone['min_order_amount']),
                'delivery_fee' => floatval($matchedZone['delivery_fee'])
            ] : null
        ]);
        
    } elseif ($action === 'zones') {
        // 获取所有配送区域
        $zones = $db->query("SELECT id, name, zone_type, min_order_amount, delivery_fee 
                             FROM delivery_zones 
                             WHERE store_id = 1 AND is_active = 1 
                             ORDER BY sort_order, id")
                    ->fetchAll();
        
        response(0, 'success', [
            'store' => [
                'name' => $store['name'],
                'lat' => floatval($store['lat']),
                'lng' => floatval($store['lng']),
                'address' => $store['address']
            ],
            'zones' => array_map(function($z) {
                return [
                    'id' => $z['id'],
                    'name' => $z['name'],
                    'type' => $z['zone_type'],
                    'min_order_amount' => floatval($z['min_order_amount']),
                    'delivery_fee' => floatval($z['delivery_fee'])
                ];
            }, $zones)
        ]);
        
    } else {
        // 获取门店基本信息（移除旧字段）
        $storeData = [
            'id' => $store['id'],
            'name' => $store['name'],
            'address' => $store['address'],
            'phone' => $store['phone'],
            'province' => $store['province'],
            'city' => $store['city'],
            'district' => $store['district'],
            'lat' => floatval($store['lat']),
            'lng' => floatval($store['lng']),
            'open_time' => $openTime,
            'close_time' => $closeTime,
            'is_open' => $store['is_open'],
            'advance_minutes' => intval($store['advance_minutes'] ?? 60),
            'notice' => $store['notice'] ?? ''
        ];
        response(0, 'success', $storeData);
    }

} catch (Exception $e) {
    response(1, '查询失败: ' . $e->getMessage());
}
