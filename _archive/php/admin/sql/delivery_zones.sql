-- 配送区域表
CREATE TABLE IF NOT EXISTS delivery_zones (
    id INT PRIMARY KEY AUTO_INCREMENT,
    store_id INT NOT NULL DEFAULT 1,
    name VARCHAR(100) NOT NULL DEFAULT '配送范围',
    zone_group ENUM('daily', 'special') NOT NULL DEFAULT 'daily' COMMENT '分组：daily日常 / special特殊时段',
    zone_type ENUM('circle', 'polygon', 'distance') NOT NULL DEFAULT 'circle',
    -- 圆形：中心点坐标 + 半径（米）
    center_lat DECIMAL(10, 8) DEFAULT NULL,
    center_lng DECIMAL(11, 8) DEFAULT NULL,
    radius_meters INT DEFAULT NULL,
    -- 多边形：JSON数组存储顶点坐标 [{"lat": x, "lng": y}, ...]
    polygon_points JSON DEFAULT NULL,
    -- 导航距离模式：最大距离（米）
    max_distance_meters INT DEFAULT NULL,
    -- 配送费用设置
    min_order_amount DECIMAL(10, 2) NOT NULL DEFAULT 0 COMMENT '起送价（元）',
    delivery_fee DECIMAL(10, 2) NOT NULL DEFAULT 0 COMMENT '配送费（元）',
    -- 时段设置（可选，JSON格式）
    time_rules JSON DEFAULT NULL COMMENT '时段规则，如 {"start": "09:00", "end": "22:00"}',
    is_active TINYINT NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_store_active (store_id, is_active),
    INDEX idx_store_group (store_id, zone_group),
    INDEX idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='门店配送区域表';
