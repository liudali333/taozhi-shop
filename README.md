# 桃之商城 - 微信小程序前端

一套基于微信小程序原生框架开发的商城前端项目，配套 PHP + MySQL 后端，支持商品展示、购物车、订单、微信支付、优惠券、配送等完整电商功能。

---

## 技术栈

| 层级 | 技术 |
|------|------|
| 前端 | 微信小程序原生框架（WXML + WXSS + JS） |
| 后端 | PHP 8.x + MySQL 8.0 |
| 地图 | 高德地图 SDK + Web 服务 API |
| 支付 | 微信支付 APIv3 |
| 配送 | 达达开放平台（可选） |

---

## 功能特性

- **首页** — 轮播图、一二级商品分类树、商品列表、搜索
- **商品详情** — 多规格、SKU 选择、详情图展示、分享
- **购物车** — 本地 + 服务端双存储、数量增减、删除、结算
- **订单系统** — 创建订单、待付款/待发货/已完成状态流转、取消订单
- **微信支付** — 统一下单、支付回调、订单状态同步
- **地址管理** — 新增/编辑/删除收货地址、地图选址
- **优惠券** — 领取优惠券、下单核销、过期提醒（订阅消息）
- **配送** — 自提/配送切换、配送范围计算、达达第三方配送对接
- **个人中心** — 头像昵称设置、订单入口、资产展示

---

## 目录结构

```
miniprogram/
├── app.js                 # 全局入口：API 基地址、购物车持久化、分享配置
├── app.json               # 页面路由、TabBar、导航栏、权限声明
├── app.wxss               # 全局样式变量、通用工具类
├── sitemap.json           # 微信搜索索引
├── project.config.json    # 开发者工具配置
│
├── pages/
│   ├── home/              # 首页（轮播 + 分类 + 商品列表）
│   ├── product/           # 商品详情（规格选择、加入购物车）
│   ├── cart/              # 购物车（结算、配送方式选择）
│   ├── search/            # 搜索页
│   ├── order/             # 订单列表（tab 切换：全部/待付款/待收货/已完成）
│   ├── order-detail/      # 订单详情
│   ├── my/                # 个人中心（头像、昵称、订单入口、资产）
│   ├── address/           # 收货地址管理
│   ├── map-picker/        # 地图选址（高德地图）
│   ├── coupon/            # 优惠券列表
│   └── receive-coupon/    # 领取优惠券
│
└── images/                # 静态图标（TabBar、分类、订单状态等）
```

---

## 快速开始

### 1. 克隆项目

```bash
git clone https://github.com/yourname/taozhi-miniprogram.git
cd taozhi-miniprogram/miniprogram
```

### 2. 导入微信开发者工具

- 打开「微信开发者工具」
- 选择「导入项目」，指向 `miniprogram` 目录
- 填写你的小程序 AppID（测试号也可）

### 3. 配置 API 地址

修改 `app.js` 中的 `apiBase`：

```javascript
const apiBase = 'https://your-domain.com';
```

### 4. 配置高德地图 Key

修改 `app.js` 中的 `amapWebKey`，并在 `app.json` 中替换 `amap.key`：

```javascript
const amapWebKey = '你的高德 Web 服务 Key';
```

```json
{
  "amap": {
    "key": "你的高德小程序 Key"
  }
}
```

> 申请地址：[高德开放平台](https://lbs.amap.com/)

### 5. 编译预览

点击开发者工具「编译」，即可在模拟器中预览。

---

## 后端部署（简要）

后端源码位于项目根目录的 `php/` 文件夹，需要配合以下环境：

| 依赖 | 版本 |
|------|------|
| PHP | >= 8.0（需启用 PDO、fileinfo、openssl 扩展） |
| MySQL | >= 8.0 |
| Web 服务器 | Nginx / Apache |

### 核心配置

1. **数据库** — 编辑 `php/db_config.php`，填入数据库连接信息
2. **微信配置** — 编辑 `php/wechat_config.php`，填入小程序 AppID / Secret、微信支付商户信息
3. **上传目录** — 确保 `php/../uploads/` 目录可写（0755）
4. **微信支付证书** — 将商户证书放到 `php/cert/` 目录

### 数据库表

首次访问各 API 接口时会自动建表，也可手动导入 `php/sql_migration_share.sql` 作为参考。

主要表：`users`、`products`、`categories`、`orders`、`order_items`、`cart`、`addresses`、`banners`、`coupons`

---

## API 接口清单

| 接口 | 文件 | 说明 |
|------|------|------|
| `POST /api/user.php?action=login` | `user.php` | 微信登录（code 换 openid） |
| `POST /api/user.php?action=update` | `user.php` | 更新用户昵称/头像/手机 |
| `POST /api/user.php?action=update_avatar` | `user.php` | 上传用户头像 |
| `GET /api/product.php` | `product.php` | 商品列表/详情 |
| `GET /api/category.php` | `category.php` | 商品分类树 |
| `GET /api/banner.php` | `banner.php` | 首页轮播图 |
| `POST /api/cart.php` | `cart.php` | 购物车读写 |
| `POST /api/order.php?action=create` | `order.php` | 创建订单 |
| `GET /api/order.php` | `order.php` | 订单列表/详情 |
| `POST /api/pay.php?action=prepay` | `pay.php` | 微信支付预下单 |
| `POST /api/address.php` | `address.php` | 地址增删改查 |
| `POST /api/upload.php` | `upload.php` | 图片上传（支持主图/详情图/轮播图/头像） |
| `GET /api/store.php` | `store.php` | 门店信息（配送费、配送范围） |
| `POST /api/coupon.php` | `coupon.php` | 优惠券领取/查询/核销 |
| `GET /api/share.php` | `share.php` | 页面分享配置 |

---

## 管理后台

项目附带一个纯 PHP + HTML 的管理后台，无需前端构建工具。

访问地址：`https://your-domain.com/admin/login.php`

功能模块：商品管理、分类管理、轮播图管理、订单管理、用户管理、优惠券管理、门店管理。

---



## 注意事项

1. **微信小程序域名配置** — 生产环境需在「微信公众平台」配置 request 合法域名（你的 API 地址 + `https://api.weixin.qq.com`）
2. **HTTPS 要求** — 微信小程序要求所有网络请求必须使用 HTTPS
3. **地理位置权限** — 配送功能需要用户授权位置信息，已在 `app.json` 中声明
4. **订阅消息** — 优惠券提醒功能需在微信公众平台申请对应模板 ID

---

## 开源协议

MIT License

---

## 相关仓库

- 前端（本仓库）：微信小程序原生框架
- 后端：`php/` 目录下的 PHP API + 管理后台

如有问题，欢迎提交 Issue 或 PR。
