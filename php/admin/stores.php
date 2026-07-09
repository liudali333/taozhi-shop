<?php
require_once 'config.php';
checkAuth();

$msg = '';
$activeTab = $_GET['tab'] ?? 'basic';

try {
    $db = getDB();

    // 基本信息保存
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_basic') {
        $db->prepare("UPDATE store_single SET name=?, address=?, phone=?, province=?, city=?, district=?, lat=?, lng=?, open_time=?, close_time=?, advance_minutes=?, notice=? WHERE id=1")
           ->execute([
               $_POST['name'], 
               $_POST['address'], 
               $_POST['phone'], 
               $_POST['province'] ?? '', 
               $_POST['city'] ?? '', 
               $_POST['district'] ?? '',
               floatval($_POST['lat'] ?? 0), 
               floatval($_POST['lng'] ?? 0), 
               $_POST['open_time'] . ':00', 
               $_POST['close_time'] . ':00', 
               intval($_POST['advance_minutes'] ?? 60), 
               $_POST['notice'] ?? ''
           ]);
        header("Location: stores.php?tab=basic&msg=保存成功");
        exit;
    }

    // 删除配送区域
    if (isset($_GET['action']) && $_GET['action'] === 'delete_zone' && !empty($_GET['zone_id'])) {
        $db->prepare("DELETE FROM delivery_zones WHERE id = ?")
           ->execute([intval($_GET['zone_id'])]);
        header("Location: stores.php?tab=zones&msg=删除成功");
        exit;
    }

    if (isset($_GET['msg']) && $_GET['msg']) $msg = $_GET['msg'];
    
    // 获取门店信息
    $store = $db->query("SELECT * FROM store_single WHERE id = 1")->fetch();
    if (!$store) {
        $db->query("INSERT INTO store_single (id, name) VALUES (1, '桃之成人用品')");
        $store = $db->query("SELECT * FROM store_single WHERE id = 1")->fetch();
    }
    $store['open_time'] = substr($store['open_time'] ?? '09:00:00', 0, 5);
    $store['close_time'] = substr($store['close_time'] ?? '22:00:00', 0, 5);

    // 获取配送区域列表（按分组）
    $dailyZones = $db->query("SELECT * FROM delivery_zones WHERE store_id = 1 AND zone_group = 'daily' ORDER BY sort_order, id")->fetchAll();
    $specialZones = $db->query("SELECT * FROM delivery_zones WHERE store_id = 1 AND zone_group = 'special' ORDER BY sort_order, id")->fetchAll();

    // 一键切换模式
    if (isset($_GET['action']) && $_GET['action'] === 'switch_mode' && isset($_GET['mode'])) {
        $mode = $_GET['mode'] === 'special' ? 'special' : 'daily';
        // 停用所有，然后启用指定分组的
        $db->query("UPDATE delivery_zones SET is_active = 0 WHERE store_id = 1");
        $db->prepare("UPDATE delivery_zones SET is_active = 1 WHERE store_id = 1 AND zone_group = ?")->execute([$mode]);
        header('Location: ?tab=zones&msg=' . urlencode('已切换到' . ($mode === 'daily' ? '日常' : '特殊时段') . '配送范围'));
        exit;
    }

    // 编辑单个区域
    $editZone = null;
    if (isset($_GET['action']) && $_GET['action'] === 'edit_zone' && !empty($_GET['zone_id'])) {
        $stmt = $db->prepare("SELECT * FROM delivery_zones WHERE id = ?");
        $stmt->execute([intval($_GET['zone_id'])]);
        $editZone = $stmt->fetch();
    }

    // 保存配送区域（支持 zone_group）
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_zone') {
        $zoneId = intval($_POST['zone_id'] ?? 0);
        $data = [
            'name' => $_POST['name'] ?? '配送范围',
            'zone_group' => ($_POST['zone_group'] ?? 'daily') === 'special' ? 'special' : 'daily',
            'zone_type' => $_POST['zone_type'] ?? 'circle',
            'center_lat' => floatval($_POST['center_lat'] ?? 0),
            'center_lng' => floatval($_POST['center_lng'] ?? 0),
            'radius_meters' => intval((floatval($_POST['radius_meters'] ?? 0)) * 1000),
            'max_distance_meters' => intval((floatval($_POST['max_distance_meters'] ?? 0)) * 1000),
            'polygon_points' => $_POST['polygon_points'] ?? '[]',
            'min_order_amount' => floatval($_POST['min_order_amount'] ?? 0),
            'delivery_fee' => floatval($_POST['delivery_fee'] ?? 0),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'sort_order' => intval($_POST['sort_order'] ?? 0),
        ];

        if ($zoneId > 0) {
            $stmt = $db->prepare("UPDATE delivery_zones SET name=?, zone_group=?, zone_type=?, center_lat=?, center_lng=?, radius_meters=?, max_distance_meters=?, polygon_points=?, min_order_amount=?, delivery_fee=?, is_active=?, sort_order=? WHERE id=?");
            $stmt->execute([$data['name'], $data['zone_group'], $data['zone_type'], $data['center_lat'], $data['center_lng'], $data['radius_meters'], $data['max_distance_meters'], $data['polygon_points'], $data['min_order_amount'], $data['delivery_fee'], $data['is_active'], $data['sort_order'], $zoneId]);
        } else {
            $stmt = $db->prepare("INSERT INTO delivery_zones (store_id, name, zone_group, zone_type, center_lat, center_lng, radius_meters, max_distance_meters, polygon_points, min_order_amount, delivery_fee, is_active, sort_order) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$data['name'], $data['zone_group'], $data['zone_type'], $data['center_lat'], $data['center_lng'], $data['radius_meters'], $data['max_distance_meters'], $data['polygon_points'], $data['min_order_amount'], $data['delivery_fee'], $data['is_active'], $data['sort_order']]);
        }
        header('Location: ?tab=zones&msg=' . urlencode('保存成功'));
        exit;
    }

} catch (Exception $e) {
    $msg = '数据库错误: ' . $e->getMessage();
    $store = null;
    $zones = [];
}

ob_start();
?>
<!-- 高德地图 JS API -->
<script src="https://webapi.amap.com/maps?v=2.0&key=fb277f76881983b28400d76343d67374&plugin=AMap.CircleEditor,AMap.PolygonEditor,AMap.MouseTool"></script>
<div class="header"><h1>🏪 门店设置</h1></div>

<?php if ($msg): ?><div class="msg-box msg-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>

<!-- 标签切换 -->
<div class="tabs" style="display:flex;border-bottom:1px solid #ddd;margin-bottom:20px;">
    <a href="?tab=basic" class="tab <?= $activeTab === 'basic' ? 'active' : '' ?>" 
       style="padding:12px 24px;text-decoration:none;color:<?= $activeTab === 'basic' ? '#07c160' : '#666' ?>;border-bottom:2px solid <?= $activeTab === 'basic' ? '#07c160' : 'transparent' ?>;font-weight:<?= $activeTab === 'basic' ? 'bold' : 'normal' ?>">
        📋 基本信息
    </a>
    <a href="?tab=zones" class="tab <?= $activeTab === 'zones' ? 'active' : '' ?>"
       style="padding:12px 24px;text-decoration:none;color:<?= $activeTab === 'zones' ? '#07c160' : '#666' ?>;border-bottom:2px solid <?= $activeTab === 'zones' ? '#07c160' : 'transparent' ?>;font-weight:<?= $activeTab === 'zones' ? 'bold' : 'normal' ?>">
        🗺️ 配送区域
    </a>
</div>

<?php if ($activeTab === 'basic'): ?>
<!-- ========== 基本信息 ========== -->
<div class="card">
    <?php if ($store): ?>
    <form method="POST" id="storeForm">
        <input type="hidden" name="action" value="save_basic">
        
        <div class="form-row"><label class="form-label">门店名称</label><input type="text" name="name" class="form-input" value="<?= htmlspecialchars($store['name']) ?>" required></div>
        
        <!-- 省市区选择 -->
        <div class="form-row">
            <label class="form-label">所在地区</label>
            <div style="display:flex;gap:8px;">
                <select name="province" id="provinceSelect" class="form-input" style="flex:1;" onchange="loadCities()">
                    <option value="">请选择省份</option>
                </select>
                <select name="city" id="citySelect" class="form-input" style="flex:1;" onchange="loadDistricts()">
                    <option value="">请选择城市</option>
                </select>
                <select name="district" id="districtSelect" class="form-input" style="flex:1;">
                    <option value="">请选择区县</option>
                </select>
            </div>
        </div>

        <!-- 地址搜索 + 自动补全 -->
        <div class="form-row">
            <label class="form-label">详细地址</label>
            <div style="position:relative;">
                <input type="text" id="addressInput" name="address" class="form-input" 
                       value="<?= htmlspecialchars($store['address']) ?>" 
                       placeholder="输入街道、门牌号等详细地址"
                       oninput="searchAddress()" 
                       onfocus="showSuggestions()" 
                       onblur="hideSuggestions()"
                       autocomplete="off"
                       style="flex:1;">
                <button type="button" id="geocodeBtn" class="btn btn-secondary" onclick="openMapWithAddress()">🗺️ 定位</button>
                <div id="suggestions" style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:70px;background:#fff;border:1px solid #ddd;border-top:none;max-height:200px;overflow-y:auto;z-index:1000;"></div>
            </div>
            <input type="hidden" id="selectedAddress" value="">
            <input type="hidden" id="latInput" name="lat" value="<?= $store['lat'] ?>">
            <input type="hidden" id="lngInput" name="lng" value="<?= $store['lng'] ?>">
        </div>
        <div class="form-row">
            <small id="geoStatus" style="color:#666;font-size:12px;">
                <?php if ($store['lat'] && $store['lng']): ?>
                    ✓ 已定位：<?= $store['lat'] ?>, <?= $store['lng'] ?>
                <?php else: ?>
                    选择省市区并输入详细地址后，点击「🗺️ 定位」自动定位
                <?php endif; ?>
            </small>
        </div>

        <div class="form-row"><label class="form-label">联系电话</label><input type="text" name="phone" class="form-input" value="<?= htmlspecialchars($store['phone']) ?>"></div>
        
        <div class="form-row form-row-inline-3">
            <div><label class="form-label">营业开始</label><input type="time" name="open_time" class="form-input" value="<?= htmlspecialchars($store['open_time']) ?>"></div>
            <div><label class="form-label">营业结束</label><input type="time" name="close_time" class="form-input" value="<?= htmlspecialchars($store['close_time']) ?>"></div>
            <div><label class="form-label">提前预定（分钟）</label><input type="number" name="advance_minutes" class="form-input" value="<?= $store['advance_minutes'] ?? 60 ?>" min="0"></div>
        </div>
        
        <div class="form-row"><label class="form-label">门店公告</label><textarea name="notice" class="form-textarea" placeholder="显示在小程序首页的公告，如：满59元包配送，保护您的隐私"><?= htmlspecialchars($store['notice'] ?? '') ?></textarea></div>
        
        <div class="form-actions"><button type="submit" class="btn btn-primary">💾 保存设置</button></div>
    </form>
    <?php else: ?><div style="color:#e64340;">数据库连接失败</div><?php endif; ?>
</div>

<?php else: ?>
<!-- ========== 配送区域 ========== -->

<?php if (isset($_GET['action']) && $_GET['action'] === 'edit_zone'): ?>
<!-- ========== 内嵌编辑/添加配送区域 ========== -->
<div class="card">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
        <a href="?tab=zones" style="font-size:20px;color:#666;text-decoration:none;">←</a>
        <h3 style="margin:0;font-size:18px;"><?= $editZone ? '✏️ 编辑配送范围' : '+ 添加配送范围' ?></h3>
    </div>
    <form method="POST" id="zoneForm" style="display:flex;height:calc(100vh - 200px);">
            <input type="hidden" name="action" value="save_zone">
            <input type="hidden" name="zone_id" value="<?= $editZone['id'] ?? 0 ?>">

            <!-- 左侧：表单 -->
            <div style="width:320px;padding:20px;border-right:1px solid #eee;overflow-y:auto;">
                <?php $curGroup = $editZone['zone_group'] ?? ($_GET['group'] ?? 'daily'); ?>
                <div class="form-row">
                    <label class="form-label">分组</label>
                    <select name="zone_group" class="form-input">
                        <option value="daily" <?= $curGroup === 'daily' ? 'selected' : '' ?>>📅 日常配送</option>
                        <option value="special" <?= $curGroup === 'special' ? 'selected' : '' ?>>🌙 特殊时段</option>
                    </select>
                </div>

                <div class="form-row">
                    <label class="form-label">范围名称</label>
                    <input type="text" name="name" class="form-input" value="<?= htmlspecialchars($editZone['name'] ?? '配送范围') ?>" required>
                </div>

                <div class="form-row">
                    <label class="form-label">绘制方式</label>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button type="button" class="draw-type-btn <?= ($editZone['zone_type'] ?? 'circle') === 'distance' ? 'active' : '' ?>" data-type="distance" onclick="setDrawType('distance')" style="flex:1;padding:8px;border:1px solid #ddd;border-radius:4px;background:#fff;cursor:pointer;">按导航距离</button>
                        <button type="button" class="draw-type-btn <?= ($editZone['zone_type'] ?? '') === 'circle' ? 'active' : '' ?>" data-type="circle" onclick="setDrawType('circle')" style="flex:1;padding:8px;border:1px solid #ddd;border-radius:4px;background:#fff;cursor:pointer;">绘制圆形</button>
                        <button type="button" class="draw-type-btn <?= ($editZone['zone_type'] ?? '') === 'polygon' ? 'active' : '' ?>" data-type="polygon" onclick="setDrawType('polygon')" style="flex:1;padding:8px;border:1px solid #ddd;border-radius:4px;background:#fff;cursor:pointer;">手动绘制</button>
                    </div>
                    <input type="hidden" name="zone_type" id="zoneTypeInput" value="<?= $editZone['zone_type'] ?? 'circle' ?>">
                </div>

                <!-- 导航距离设置 -->
                <div id="distanceSettings" style="display:<?= ($editZone['zone_type'] ?? '') === 'distance' ? 'block' : 'none' ?>;">
                    <div class="form-row">
                        <label class="form-label">最大导航距离</label>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <input type="number" name="max_distance_meters" id="maxDistanceInput" class="form-input" value="<?= ($editZone['max_distance_meters'] ?? 5000) / 1000 ?>" step="0.1" min="0.1" style="flex:1;">
                            <span>公里</span>
                        </div>
                        <small style="color:#999;">根据实际导航路径计算距离</small>
                    </div>
                </div>

                <!-- 圆形设置 -->
                <div id="circleSettings" style="display:<?= ($editZone['zone_type'] ?? 'circle') === 'circle' ? 'block' : 'none' ?>;">
                    <div class="form-row">
                        <label class="form-label">半径</label>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <input type="number" name="radius_meters" id="radiusInput" class="form-input" value="<?= ($editZone['radius_meters'] ?? 3000) / 1000 ?>" step="0.1" min="0.1" style="flex:1;">
                            <span>公里</span>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <label class="form-label">起送价</label>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="number" name="min_order_amount" class="form-input" value="<?= $editZone['min_order_amount'] ?? 0 ?>" step="0.01" min="0" style="flex:1;">
                        <span>元</span>
                    </div>
                </div>

                <div class="form-row">
                    <label class="form-label">配送费</label>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="number" name="delivery_fee" class="form-input" value="<?= $editZone['delivery_fee'] ?? 0 ?>" step="0.01" min="0" style="flex:1;">
                        <span>元</span>
                    </div>
                </div>

                <div class="form-row">
                    <label class="form-label">状态</label>
                    <select name="is_active" class="form-input">
                        <option value="1" <?= ($editZone['is_active'] ?? 1) == 1 ? 'selected' : '' ?>>启用</option>
                        <option value="0" <?= ($editZone['is_active'] ?? 1) == 0 ? 'selected' : '' ?>>停用</option>
                    </select>
                </div>

                <div class="form-row">
                    <label class="form-label">排序</label>
                    <input type="number" name="sort_order" class="form-input" value="<?= $editZone['sort_order'] ?? 0 ?>" min="0">
                    <small style="color:#999;">数字越小越靠前</small>
                </div>

                <!-- 隐藏字段存储坐标 -->
                <input type="hidden" name="center_lat" id="centerLatInput" value="<?= $editZone['center_lat'] ?? $store['lat'] ?? 0 ?>">
                <input type="hidden" name="center_lng" id="centerLngInput" value="<?= $editZone['center_lng'] ?? $store['lng'] ?? 0 ?>">
                <input type="hidden" name="polygon_points" id="polygonPointsInput" value='<?= $editZone['polygon_points'] ?? '[]' ?>'>

                <div style="margin-top:20px;display:flex;gap:8px;">
                    <a href="?tab=zones" class="btn btn-secondary" style="flex:1;text-align:center;">取消</a>
                    <button type="submit" class="btn btn-primary" style="flex:1;">💾 保存</button>
                </div>
            </div>

            <!-- 右侧：地图 -->
            <div style="flex:1;display:flex;flex-direction:column;">
                <div style="padding:12px 16px;background:#f5f5f5;border-bottom:1px solid #eee;font-size:14px;">
                    <span id="mapHint">在地图上点击或拖拽来设置配送范围</span>
                </div>
                <div id="zoneMapContainer" style="flex:1;min-height:400px;"></div>
            </div>
        </form>
    </div>

<?php else: ?>
<!-- ========== 配送区域列表 ========== -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <h3 style="margin:0;">🗺️ 配送范围管理</h3>
        <div style="display:flex;gap:8px;">
            <a href="?tab=zones&action=switch_mode&mode=daily" class="btn <?= empty($specialZones) || ($dailyZones[0]['is_active'] ?? 0) ? 'btn-primary' : 'btn-secondary' ?>">☀️ 日常模式</a>
            <a href="?tab=zones&action=switch_mode&mode=special" class="btn <?= !empty($specialZones) && ($specialZones[0]['is_active'] ?? 0) ? 'btn-primary' : 'btn-secondary' ?>">🌙 特殊时段</a>
        </div>
    </div>

    <!-- 日常配送范围 -->
    <div style="margin-bottom:30px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
            <h4 style="margin:0;font-size:16px;color:#333;">📅 日常配送范围</h4>
            <a href="?tab=zones&action=edit_zone&group=daily" class="btn btn-primary" style="font-size:13px;padding:6px 12px;">+ 添加日常范围</a>
        </div>
        <?php if (empty($dailyZones)): ?>
            <div style="text-align:center;padding:30px;color:#999;background:#f9f9f9;border-radius:8px;">
                <div>暂无日常配送范围</div>
            </div>
        <?php else: ?>
            <div class="zones-list">
                <?php foreach ($dailyZones as $i => $zone): ?>
                <div class="zone-item" style="border:1px solid #eee;border-radius:8px;padding:16px;margin-bottom:12px;<?= $zone['is_active'] ? '' : 'opacity:0.6;' ?>">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                        <div style="flex:1;">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                                <span style="background:#07c160;color:#fff;width:24px;height:24px;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:12px;"><?= $i + 1 ?></span>
                                <strong style="font-size:16px;"><?= htmlspecialchars($zone['name']) ?></strong>
                                <?php if (!$zone['is_active']): ?><span style="background:#999;color:#fff;padding:2px 8px;border-radius:4px;font-size:12px;">已停用</span><?php endif; ?>
                                <a href="?tab=zones&action=edit_zone&zone_id=<?= $zone['id'] ?>" style="color:#07c160;font-size:14px;">编辑</a>
                            </div>
                            <div style="color:#666;font-size:14px;line-height:1.8;">
                                <?php if ($zone['zone_type'] === 'circle'): ?>
                                    📍 圆形范围：半径 <?= ($zone['radius_meters'] / 1000) ?> 公里<br>
                                <?php elseif ($zone['zone_type'] === 'polygon'): ?>
                                    📍 多边形范围：自定义边界<br>
                                <?php elseif ($zone['zone_type'] === 'distance'): ?>
                                    📍 导航距离：最大 <?= ($zone['max_distance_meters'] / 1000) ?> 公里<br>
                                <?php endif; ?>
                                💰 起送价 <?= $zone['min_order_amount'] ?> 元，配送费 <?= $zone['delivery_fee'] ?> 元
                            </div>
                        </div>
                        <a href="?tab=zones&action=delete_zone&zone_id=<?= $zone['id'] ?>"
                           onclick="return confirm('确定删除此配送范围？')"
                           style="color:#e64340;font-size:14px;padding:4px 8px;">删除</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- 特殊时段配送范围 -->
    <div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
            <h4 style="margin:0;font-size:16px;color:#333;">🌙 特殊时段配送范围</h4>
            <a href="?tab=zones&action=edit_zone&group=special" class="btn btn-primary" style="font-size:13px;padding:6px 12px;">+ 添加特殊范围</a>
        </div>
        <?php if (empty($specialZones)): ?>
            <div style="text-align:center;padding:30px;color:#999;background:#f9f9f9;border-radius:8px;">
                <div>暂无特殊时段配送范围</div>
            </div>
        <?php else: ?>
            <div class="zones-list">
                <?php foreach ($specialZones as $i => $zone): ?>
                <div class="zone-item" style="border:1px solid #eee;border-radius:8px;padding:16px;margin-bottom:12px;<?= $zone['is_active'] ? '' : 'opacity:0.6;' ?>">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                        <div style="flex:1;">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                                <span style="background:#ff9500;color:#fff;width:24px;height:24px;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:12px;"><?= $i + 1 ?></span>
                                <strong style="font-size:16px;"><?= htmlspecialchars($zone['name']) ?></strong>
                                <?php if (!$zone['is_active']): ?><span style="background:#999;color:#fff;padding:2px 8px;border-radius:4px;font-size:12px;">已停用</span><?php endif; ?>
                                <a href="?tab=zones&action=edit_zone&zone_id=<?= $zone['id'] ?>" style="color:#07c160;font-size:14px;">编辑</a>
                            </div>
                            <div style="color:#666;font-size:14px;line-height:1.8;">
                                <?php if ($zone['zone_type'] === 'circle'): ?>
                                    📍 圆形范围：半径 <?= ($zone['radius_meters'] / 1000) ?> 公里<br>
                                <?php elseif ($zone['zone_type'] === 'polygon'): ?>
                                    📍 多边形范围：自定义边界<br>
                                <?php elseif ($zone['zone_type'] === 'distance'): ?>
                                    📍 导航距离：最大 <?= ($zone['max_distance_meters'] / 1000) ?> 公里<br>
                                <?php endif; ?>
                                💰 起送价 <?= $zone['min_order_amount'] ?> 元，配送费 <?= $zone['delivery_fee'] ?> 元
                            </div>
                        </div>
                        <a href="?tab=zones&action=delete_zone&zone_id=<?= $zone['id'] ?>"
                           onclick="return confirm('确定删除此配送范围？')"
                           style="color:#e64340;font-size:14px;padding:4px 8px;">删除</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<style>
.draw-type-btn.active { background:#07c160 !important;color:#fff !important;border-color:#07c160 !important; }
</style>

<script>
// 配送区域地图绘制
var zoneMap = null;
var currentOverlay = null;
var currentEditor = null;
var storeLat = <?= $store['lat'] ?? 0 ?>;
var storeLng = <?= $store['lng'] ?? 0 ?>;

function initZoneMap() {
    var center = [storeLng || 116.397470, storeLat || 39.908823];
    
    zoneMap = new AMap.Map('zoneMapContainer', {
        center: center,
        zoom: 14
    });

    // 添加门店标记
    var storeMarker = new AMap.Marker({
        position: center,
        title: '门店位置',
        icon: 'https://webapi.amap.com/theme/v1.3/markers/n/mark_r.png'
    });
    zoneMap.add(storeMarker);

    // 根据当前类型初始化
    var zoneType = document.getElementById('zoneTypeInput').value;
    setDrawType(zoneType, false);
}

function setDrawType(type, clear = true) {
    document.getElementById('zoneTypeInput').value = type;
    
    // 更新按钮样式
    document.querySelectorAll('.draw-type-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.type === type);
    });

    // 显示/隐藏设置面板
    document.getElementById('distanceSettings').style.display = type === 'distance' ? 'block' : 'none';
    document.getElementById('circleSettings').style.display = type === 'circle' ? 'block' : 'none';

    // 清除现有覆盖物
    if (clear && currentOverlay) {
        zoneMap.remove(currentOverlay);
        currentOverlay = null;
    }
    if (currentEditor) {
        currentEditor.close();
        currentEditor = null;
    }

    var center = [parseFloat(document.getElementById('centerLngInput').value) || storeLng || 116.397470,
                  parseFloat(document.getElementById('centerLatInput').value) || storeLat || 39.908823];

    if (type === 'circle') {
        var radius = parseFloat(document.getElementById('radiusInput').value) * 1000 || 3000;
        currentOverlay = new AMap.Circle({
            center: center,
            radius: radius,
            strokeColor: '#07c160',
            strokeWeight: 2,
            strokeOpacity: 0.8,
            fillColor: '#07c160',
            fillOpacity: 0.2
        });
        zoneMap.add(currentOverlay);
        
        currentEditor = new AMap.CircleEditor(zoneMap, currentOverlay);
        currentEditor.open();
        
        currentEditor.on('adjust', function() {
            updateCircleData();
        });
        currentEditor.on('move', function() {
            updateCircleData();
        });
        
        document.getElementById('mapHint').textContent = '拖拽圆形边缘调整半径，拖拽中心点调整位置';
        
    } else if (type === 'polygon') {
        var pointsStr = document.getElementById('polygonPointsInput').value;
        var points = [];
        try {
            var parsed = JSON.parse(pointsStr);
            if (Array.isArray(parsed) && parsed.length >= 3) {
                points = parsed.map(p => [p.lng, p.lat]);
            }
        } catch(e) {}
        
        if (points.length < 3) {
            // 默认创建一个三角形
            var offset = 0.01;
            points = [
                [center[0], center[1] + offset],
                [center[0] - offset, center[1] - offset],
                [center[0] + offset, center[1] - offset]
            ];
        }
        
        currentOverlay = new AMap.Polygon({
            path: points,
            strokeColor: '#07c160',
            strokeWeight: 2,
            strokeOpacity: 0.8,
            fillColor: '#07c160',
            fillOpacity: 0.2
        });
        zoneMap.add(currentOverlay);
        
        currentEditor = new AMap.PolygonEditor(zoneMap, currentOverlay);
        currentEditor.open();
        
        currentEditor.on('adjust', function() {
            updatePolygonData();
        });
        currentEditor.on('addnode', function() {
            updatePolygonData();
        });
        currentEditor.on('removenode', function() {
            updatePolygonData();
        });
        
        document.getElementById('mapHint').textContent = '拖拽顶点调整形状，点击边线添加顶点，右键顶点删除';
        updatePolygonData();
        
    } else if (type === 'distance') {
        // 距离模式显示一个参考圆
        var maxDist = parseFloat(document.getElementById('maxDistanceInput').value) * 1000 || 5000;
        currentOverlay = new AMap.Circle({
            center: center,
            radius: maxDist,
            strokeColor: '#FF6B6B',
            strokeWeight: 2,
            strokeOpacity: 0.8,
            strokeStyle: 'dashed',
            fillColor: '#FF6B6B',
            fillOpacity: 0.1
        });
        zoneMap.add(currentOverlay);
        
        // 距离模式可拖拽中心点
        var centerMarker = new AMap.Marker({
            position: center,
            draggable: true,
            icon: 'https://webapi.amap.com/theme/v1.3/markers/n/mark_b.png'
        });
        zoneMap.add(centerMarker);
        currentOverlay.centerMarker = centerMarker;
        
        centerMarker.on('dragend', function(e) {
            var pos = e.lnglat;
            document.getElementById('centerLngInput').value = pos.getLng();
            document.getElementById('centerLatInput').value = pos.getLat();
            currentOverlay.setCenter([pos.getLng(), pos.getLat()]);
        });
        
        document.getElementById('mapHint').textContent = '拖拽中心点设置参考位置，实际配送范围按导航距离计算';
    }
}

function updateCircleData() {
    if (!currentOverlay) return;
    var center = currentOverlay.getCenter();
    var radius = currentOverlay.getRadius();
    document.getElementById('centerLngInput').value = center.getLng();
    document.getElementById('centerLatInput').value = center.getLat();
    document.getElementById('radiusInput').value = (radius / 1000).toFixed(2);
}

function updatePolygonData() {
    if (!currentOverlay) return;
    var path = currentOverlay.getPath();
    var points = path.map(p => ({ lat: p.getLat(), lng: p.getLng() }));
    document.getElementById('polygonPointsInput').value = JSON.stringify(points);
    
    // 计算中心点（多边形质心近似）
    var sumLat = 0, sumLng = 0;
    points.forEach(p => { sumLat += p.lat; sumLng += p.lng; });
    document.getElementById('centerLatInput').value = (sumLat / points.length).toFixed(6);
    document.getElementById('centerLngInput').value = (sumLng / points.length).toFixed(6);
}

// 半径输入框变化时更新圆形
if (document.getElementById('radiusInput')) {
    document.getElementById('radiusInput').addEventListener('change', function() {
        if (currentOverlay && document.getElementById('zoneTypeInput').value === 'circle') {
            currentOverlay.setRadius(parseFloat(this.value) * 1000);
        }
    });
}

// 距离输入框变化时更新参考圆
if (document.getElementById('maxDistanceInput')) {
    document.getElementById('maxDistanceInput').addEventListener('change', function() {
        if (currentOverlay && document.getElementById('zoneTypeInput').value === 'distance') {
            currentOverlay.setRadius(parseFloat(this.value) * 1000);
        }
    });
}

// 表单提交前更新数据
document.getElementById('zoneForm').addEventListener('submit', function(e) {
    var type = document.getElementById('zoneTypeInput').value;
    if (type === 'circle') {
        updateCircleData();
    } else if (type === 'polygon') {
        updatePolygonData();
    }
    // 距离：米转回公里显示，但保存时已经是米
    if (type === 'distance') {
        var km = parseFloat(document.getElementById('maxDistanceInput').value);
        document.getElementById('maxDistanceInput').value = km * 1000;
    }
});

// 初始化地图
setTimeout(initZoneMap, 100);
</script>

<?php endif; ?>

<!-- 地图微调弹窗（基本信息用） -->
<div id="mapModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;justify-content:center;align-items:center;">
    <div style="background:#fff;border-radius:12px;width:90%;max-width:800px;max-height:90vh;display:flex;flex-direction:column;">
        <div style="padding:16px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;">
            <h3 style="margin:0;font-size:16px;">🎯 拖拽标记微调位置</h3>
            <button type="button" onclick="closeMap()" style="background:none;border:none;font-size:20px;cursor:pointer;">×</button>
        </div>
        <div id="mapContainer" style="flex:1;min-height:400px;"></div>
        <div style="padding:12px 16px;border-top:1px solid #eee;display:flex;justify-content:space-between;align-items:center;">
            <small id="mapCoord" style="color:#666;">拖拽标记到准确位置</small>
            <div style="display:flex;gap:8px;">
                <button type="button" class="btn btn-secondary" onclick="closeMap()">取消</button>
                <button type="button" class="btn btn-primary" onclick="confirmMap()">确定</button>
            </div>
        </div>
    </div>
</div>

<script>
// ==================== 省市区数据 ====================
var amapKey = 'fb277f76881983b28400d76343d67374';
var provinceData = [];
var cityData = [];
var districtData = [];

function loadProvinces() {
    fetch(`https://restapi.amap.com/v3/config/district?key=${amapKey}&keywords=&subdistrict=1&extensions=base`)
        .then(r => r.json())
        .then(data => {
            if (data.status === '1' && data.districts[0].districts) {
                provinceData = data.districts[0].districts;
                var sel = document.getElementById('provinceSelect');
                sel.innerHTML = '<option value="">请选择省份</option>';
                provinceData.forEach(p => {
                    var opt = document.createElement('option');
                    opt.value = p.adcode;
                    opt.textContent = p.name;
                    sel.appendChild(opt);
                });
                <?php if (!empty($store['province'])): ?>
                sel.value = '<?= htmlspecialchars($store['province']) ?>';
                if (sel.value) loadCities(true);
                <?php endif; ?>
            }
        });
}

function loadCities(isInit = false) {
    var provCode = document.getElementById('provinceSelect').value;
    if (!provCode) {
        document.getElementById('citySelect').innerHTML = '<option value="">请选择城市</option>';
        document.getElementById('districtSelect').innerHTML = '<option value="">请选择区县</option>';
        return;
    }
    var province = provinceData.find(p => p.adcode == provCode);
    if (!province) return;

    fetch(`https://restapi.amap.com/v3/config/district?key=${amapKey}&keywords=${province.name}&subdistrict=1&extensions=base`)
        .then(r => r.json())
        .then(data => {
            if (data.status === '1' && data.districts[0].districts) {
                cityData = data.districts[0].districts;
                var sel = document.getElementById('citySelect');
                sel.innerHTML = '<option value="">请选择城市</option>';
                cityData.forEach(c => {
                    var opt = document.createElement('option');
                    opt.value = c.adcode;
                    opt.textContent = c.name;
                    sel.appendChild(opt);
                });
                if (!isInit) {
                    document.getElementById('districtSelect').innerHTML = '<option value="">请选择区县</option>';
                }
                <?php if (!empty($store['city'])): ?>
                if (isInit) {
                    sel.value = '<?= htmlspecialchars($store['city']) ?>';
                    if (sel.value) loadDistricts(true);
                }
                <?php endif; ?>
            }
        });
}

function loadDistricts(isInit = false) {
    var cityCode = document.getElementById('citySelect').value;
    if (!cityCode) {
        document.getElementById('districtSelect').innerHTML = '<option value="">请选择区县</option>';
        return;
    }
    var city = cityData.find(c => c.adcode == cityCode);
    if (!city) return;

    fetch(`https://restapi.amap.com/v3/config/district?key=${amapKey}&keywords=${city.name}&subdistrict=1&extensions=base`)
        .then(r => r.json())
        .then(data => {
            if (data.status === '1' && data.districts[0].districts) {
                districtData = data.districts[0].districts;
                var sel = document.getElementById('districtSelect');
                sel.innerHTML = '<option value="">请选择区县</option>';
                districtData.forEach(d => {
                    var opt = document.createElement('option');
                    opt.value = d.adcode;
                    opt.textContent = d.name;
                    sel.appendChild(opt);
                });
                <?php if (!empty($store['district'])): ?>
                if (isInit) {
                    sel.value = '<?= htmlspecialchars($store['district']) ?>';
                }
                <?php endif; ?>
            }
        });
}

// ==================== 地图定位功能 ====================
var mapInstance = null;
var mapMarker = null;

function openMapWithAddress() {
    var province = document.getElementById('provinceSelect');
    var city = document.getElementById('citySelect');
    var district = document.getElementById('districtSelect');
    var address = document.getElementById('addressInput').value.trim();
    
    var provinceName = province.selectedIndex > 0 ? province.options[province.selectedIndex].text : '';
    var cityName = city.selectedIndex > 0 ? city.options[city.selectedIndex].text : '';
    var districtName = district.selectedIndex > 0 ? district.options[district.selectedIndex].text : '';
    
    if (!provinceName) { alert('请先选择省份'); return; }
    if (!address) { alert('请输入详细地址'); return; }
    
    var fullAddress = provinceName + cityName + districtName + address;
    var btn = document.getElementById('geocodeBtn');
    btn.disabled = true; btn.textContent = '定位中...';
    var statusEl = document.getElementById('geoStatus');
    if (statusEl) { statusEl.textContent = '正在定位...'; statusEl.style.color = '#666'; }
    
    fetch(`https://restapi.amap.com/v3/geocode/geo?key=${amapKey}&address=${encodeURIComponent(fullAddress)}&city=${encodeURIComponent(cityName)}`)
        .then(r => r.json())
        .then(data => {
            btn.disabled = false; btn.textContent = '🗺️ 定位';
            var lat, lng;
            if (data.status === '1' && data.geocodes && data.geocodes.length > 0) {
                var loc = data.geocodes[0].location.split(',');
                lng = parseFloat(loc[0]); lat = parseFloat(loc[1]);
                if (statusEl) { statusEl.textContent = '✓ 已定位到：' + fullAddress + '（拖拽标记微调）'; statusEl.style.color = '#07c160'; }
            } else {
                lat = parseFloat(document.getElementById('latInput').value) || 39.908823;
                lng = parseFloat(document.getElementById('lngInput').value) || 116.397470;
                if (statusEl) { statusEl.textContent = '⚠ 地址解析失败，请在地图上手动拖动标记定位'; statusEl.style.color = '#ff6600'; }
            }
            document.getElementById('latInput').value = lat;
            document.getElementById('lngInput').value = lng;
            openMap(lat, lng);
        })
        .catch(function() {
            btn.disabled = false; btn.textContent = '🗺️ 定位';
            var lat = parseFloat(document.getElementById('latInput').value) || 39.908823;
            var lng = parseFloat(document.getElementById('lngInput').value) || 116.397470;
            if (statusEl) { statusEl.textContent = '⚠ 网络错误，请在地图上手动定位'; statusEl.style.color = '#e64340'; }
            openMap(lat, lng);
        });
}

function openMap(lat, lng) {
    document.getElementById('mapModal').style.display = 'flex';
    setTimeout(function() {
        if (mapInstance) mapInstance.destroy();
        mapInstance = new AMap.Map('mapContainer', { center: [lng, lat], zoom: 16 });
        mapMarker = new AMap.Marker({ position: [lng, lat], draggable: true, cursor: 'move' });
        mapMarker.on('dragend', function(e) {
            var pos = e.lnglat;
            document.getElementById('mapCoord').textContent = '当前坐标：' + pos.getLat().toFixed(6) + ', ' + pos.getLng().toFixed(6);
        });
        mapInstance.on('click', function(e) {
            mapMarker.setPosition(e.lnglat);
            document.getElementById('mapCoord').textContent = '当前坐标：' + e.lnglat.getLat().toFixed(6) + ', ' + e.lnglat.getLng().toFixed(6);
        });
        mapInstance.add(mapMarker);
        document.getElementById('mapCoord').textContent = '当前坐标：' + lat.toFixed(6) + ', ' + lng.toFixed(6);
    }, 100);
}

function closeMap() {
    document.getElementById('mapModal').style.display = 'none';
    if (mapInstance) { mapInstance.destroy(); mapInstance = null; }
}

function confirmMap() {
    if (!mapMarker) return;
    var pos = mapMarker.getPosition();
    document.getElementById('latInput').value = pos.getLat();
    document.getElementById('lngInput').value = pos.getLng();
    var statusEl = document.getElementById('geoStatus');
    if (statusEl) { statusEl.textContent = '✓ 已定位：' + pos.getLat().toFixed(6) + ', ' + pos.getLng().toFixed(6); statusEl.style.color = '#07c160'; }
    closeMap();
}

// ==================== 地址搜索自动补全 ====================
var searchTimer = null;
function searchAddress() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function() {
        var keyword = document.getElementById('addressInput').value.trim();
        if (keyword.length < 2) { document.getElementById('suggestions').style.display = 'none'; return; }
        var citySelect = document.getElementById('citySelect');
        var cityName = citySelect.selectedIndex > 0 ? citySelect.options[citySelect.selectedIndex].text : '';
        fetch(`https://restapi.amap.com/v3/place/text?key=${amapKey}&keywords=${encodeURIComponent(keyword)}&city=${encodeURIComponent(cityName)}&citylimit=true&offset=10&page=1&extensions=all`)
            .then(r => r.json())
            .then(data => {
                if (data.status === '1' && data.pois) {
                    showSuggestionList(data.pois.map(poi => ({
                        name: poi.name, address: poi.address, location: poi.location, district: poi.adname || ''
                    })));
                }
            })
            .catch(err => console.error('搜索失败', err));
    }, 300);
}

function showSuggestionList(pois) {
    var box = document.getElementById('suggestions');
    if (!pois || pois.length === 0) { box.style.display = 'none'; return; }
    box.innerHTML = '';
    pois.forEach(poi => {
        var div = document.createElement('div');
        div.style.cssText = 'padding:8px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0;font-size:14px;';
        div.textContent = poi.name + (poi.address ? ' (' + poi.address + ')' : '') + (poi.district ? ' - ' + poi.district : '');
        div.onmouseover = function() { this.style.background = '#f5f5f5'; };
        div.onmouseout = function() { this.style.background = '#fff'; };
        div.onclick = function() { selectAddress(poi); };
        box.appendChild(div);
    });
    box.style.display = 'block';
}

function selectAddress(poi) {
    var fullAddress = poi.address ? poi.address + poi.name : poi.name;
    document.getElementById('addressInput').value = fullAddress;
    document.getElementById('selectedAddress').value = fullAddress;
    document.getElementById('suggestions').style.display = 'none';
    if (poi.location && poi.location.includes(',')) {
        var loc = poi.location.split(',');
        document.getElementById('lngInput').value = parseFloat(loc[0]);
        document.getElementById('latInput').value = parseFloat(loc[1]);
        openMap(parseFloat(loc[1]), parseFloat(loc[0]));
    }
}

function showSuggestions() {
    var box = document.getElementById('suggestions');
    if (box.children.length > 0) box.style.display = 'block';
}
function hideSuggestions() {
    setTimeout(function() { document.getElementById('suggestions').style.display = 'none'; }, 200);
}

window.onload = function() { loadProvinces(); };
</script>

<?php
$pageContent = ob_get_clean();
$pageTitle = '门店设置 - 桃之后台';
include 'layout.php';
