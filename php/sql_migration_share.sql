USE taozhi;

CREATE TABLE IF NOT EXISTS share_configs (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  page_key VARCHAR(50) NOT NULL UNIQUE COMMENT '页面标识',
  page_name VARCHAR(50) NOT NULL COMMENT '页面名称',
  share_title VARCHAR(100) DEFAULT '' COMMENT '分享标题',
  share_img VARCHAR(500) DEFAULT '' COMMENT '分享图片URL',
  status TINYINT DEFAULT 1 COMMENT '1=启用 0=禁用',
  sort INT DEFAULT 0 COMMENT '排序',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT '页面分享配置';

INSERT INTO share_configs (page_key, page_name, share_title, sort) VALUES
('home', '首页', '桃之 — 私密情趣，用心甄选', 1),
('category', '分类页', '精选分类 - 桃之', 2),
('product', '商品详情', '好物推荐 - 桃之', 3),
('cart', '购物车', '我的购物车 - 桃之', 4),
('coupons', '优惠券中心', '领券中心 - 桃之', 5),
('mine', '个人中心', '我的 - 桃之', 6),
('order_detail', '订单详情', '订单详情 - 桃之', 7)
ON DUPLICATE KEY UPDATE page_name=VALUES(page_name);

ALTER TABLE banners DROP COLUMN share_img;
