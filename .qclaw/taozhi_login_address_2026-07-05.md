# 桃之 - 微信登录 + 收货地址 + 购物车改造 (2026-07-05 11:47)

## 改动范围

### 1. 微信授权登录 (my.js + my.wxml)
- 移除已废弃的 `wx.getUserProfile`，改用 `wx.login()` + 后端 code2session 
- 双模式登录：
  - **配置了 AppID/Secret**：后端通过 WeChat API 换 openid，创建/查找用户，返回真实 DB ID
  - **未配置/后端不通**：本地 fallback，用 code 的 hash 生成持久一致的 user_id（同一设备始终相同）
- 首次登录弹出昵称设置弹窗
- 后端 `user.php` 新增 `local_id` 参数支持

### 2. 后端用户管理 (user.php + address.php + wechat_config.php)
- **user.php login**：接收 code + local_id，优先用 WeChat API 换 openid，无配置时用 local_id 持久化
- **address.php**：用户地址 CRUD API（list + sync），自动建表 `user_addresses`
- **wechat_config.php**：配置文件模板（填写 appid/secret 后启用全功能）

### 3. 收货地址页面 (pages/address/)
- 地址列表（卡片式，默认标签，编辑/删除/设为默认）
- 新增/编辑弹窗（name/phone/省市区picker/详细地址/**地图选点 wx.chooseLocation**）
- 地址选择后自动返回购物车页（携带地址数据）
- 同步后端（后端在线时自动 sync）

### 4. 购物车顶部改地址栏 (cart.wxml + cart.js + cart.wxss)
- 顶部「商家信息」改为「收货地址栏」
  - 显示收货人+电话+地址
  - 无地址时显示「请添加收货地址」
  - 点击跳转到地址页选择
- 门店信息折叠为小提示条（店名 + 营业时间 + 公告）
- 配送范围自动检测（超出时禁用跑腿配送、自动切自提）
- 结算流程：检查营业状态 → 地址 → 配送范围 → 登录 → 提交流程不变

## 文件清单

| 文件 | 说明 |
|------|------|
| `miniprogram/pages/address/address.*` | 收货地址页面（4文件，新建） |
| `miniprogram/pages/cart/cart.*` | 购物车（顶部改地址栏 + 门店折叠） |
| `miniprogram/pages/my/my.*` | 我的页面（真实微信登录流程） |
| `php/api/user.php` | 用户 API（login/assets/update） |
| `php/api/address.php` | 地址 API（list/sync，自动建表） |
| `php/wechat_config.php` | 微信配置模板 |
| `miniprogram/app.json` | 新增 address 页面注册 |

## 登录数据流

```
用户点击"微信一键登录"
  ↓
wx.login() → 获取 code
  ↓ 计算 localId = hashStr(code)
wx.request → POST /api/user.php?action=login { code, local_id }
  ├─ 有 appid/secret → code2session → 查/建 users 表 → 返回真实 user.id
  ├─ 无 appid/secret → 用 local_id 作为 openid → 查/建 users 表 → 返回 user
  └─ 后端不通 → 本地 fallback → 存本地 userInfo (ID 基于 hash 持久化)

用户 ID 始终一致：后端真实 DB id 或本地 hash id
收货地址用 user_id 关联，本地存储 + 后端同步双轨
```

## 待用户配置
1. 部署后端后，修改 `wechat_config.php` 填入 AppID/AppSecret（可在微信公众平台获取）
2. 或维持现状：无需配置，backend 用 local_id 持久化用户，地址同步到后端
