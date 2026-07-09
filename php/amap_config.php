<?php
/**
 * 高德地图配置
 */

define('AMAP_WEB_KEY', 'fb277f76881983b28400d76343d67374');   // Web服务API Key
define('AMAP_MINI_KEY', 'c6bc075b823845754545a2572c27457b'); // 微信小程序SDK Key

/**
 * 发送 HTTP GET 请求
 */
function amap_http_get($url, $params = []) {
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 10,
            'ignore_errors' => true
        ]
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    return $resp ? json_decode($resp, true) : null;
}

/**
 * 地址 → 经纬度（地理编码）
 * @param string $address
 * @param string $key
 * @return array ['lat' => float, 'lng' => float] | null
 */
function amap_geocode($address, $key) {
    $res = amap_http_get('https://restapi.amap.com/v3/geocode/geo', [
        'key' => $key,
        'address' => $address,
        'output' => 'JSON'
    ]);
    if ($res && !empty($res['geocodes'])) {
        $loc = $res['geocodes'][0]['location'];
        $parts = explode(',', $loc);
        if (count($parts) === 2) {
            return [
                'lng' => floatval($parts[0]),
                'lat' => floatval($parts[1])
            ];
        }
    }
    return null;
}

/**
 * 经纬度 → 地址（逆地理编码）
 * @param float $lat
 * @param float $lng
 * @param string $key
 * @return string|null
 */
function amap_reverse_geocode($lat, $lng, $key) {
    $res = amap_http_get('https://restapi.amap.com/v3/geocode/regeo', [
        'key' => $key,
        'location' => "{$lng},{$lat}",
        'output' => 'JSON'
    ]);
    if ($res && !empty($res['regeocode']['formatted_address'])) {
        return $res['regeocode']['formatted_address'];
    }
    return null;
}

/**
 * 计算两点间距离（米）
 * @param float $lat1\n * @param float $lng1
 * @param float $lat2
 * @param float $lng2
 * @param string $key
 * @return int|null
 */
function amap_distance($lat1, $lng1, $lat2, $lng2, $key) {
    $res = amap_http_get('https://restapi.amap.com/v3/distance', [
        'key' => $key,
        'origins' => "{$lng1},{$lat1}",
        'destination' => "{$lng2},{$lat2}",
        'type' => 0  // 直线距离
    ]);
    if ($res && !empty($res['results'][0]['distance'])) {
        return intval($res['results'][0]['distance']);
    }
    return null;
}
