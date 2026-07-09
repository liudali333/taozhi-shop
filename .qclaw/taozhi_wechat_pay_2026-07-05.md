# 桃之 - 一键支付：合并「去结算」与「微信支付」 (2026-07-05 12:30)

## 需求
用户希望把购物车「去结算」按钮直接改为「支付」，
点击后直接调用**真实微信支付**（V3 API）。

## 后端新增

### `wechat_pay_config.php` (1186 字节)
商户支付配置模板，需填：
- mch_id（商户号）
- serial_no（API 证书序列号）
- private_key（apiclient_key.pem 内容）
- api_v3_key（APIv3 密钥）
- notify_url（支付回调地址，必须 HTTPS）

### `api/pay.php` (8191 字节)
微信支付 V3 API 封装，路由：
- `?action=prepay` - 创建预付单 + 生成 JSAPI 调起参数
- `?action=query` - 查询订单
- `?action=close` - 关闭订单

实现要点：
- `wechatPaySign()` 用 SHA256withRSA 签 V3 头
- `wechatPayRequest()` 带 Authorization 头调微信 API
- `makePaySign()` 生成前端调起支付参数（paySign）
- 依赖 PHP openssl + curl 扩展

### `api/pay_notify.php` (3245 字节)
支付回调处理器：
- 验证微信回调签名（用平台证书公钥）
- AEAD-AES-256-GCM 解密回调数据
- 更新订单状态为 `paid`
- 记录 paid_at、transaction_id

### `api/order.php` (7712 字节) 重写
- 自动迁移：建表 + 补缺失字段（prepay_id、paid_at、transaction_id 等）
- 接收新字段：user_id、store_id、store_name、consignee_* 等
- 路由：`?action=create` `?action=mark_paid` `?action=cancel` `?action=detail` 列表

### `api/user.php` 修改
- 登录响应返回 `openid` 字段（支付必需）

## 前端改动

### `pages/cart/cart.js` 重构支付流程
- 新增 `paying` 状态（防止重复点击）
- 删 `goCheckout`，改 `goPay`：一键流程
  1. 创建订单 → 2. 获取 prepay_id → 3. 调起 wx.requestPayment
- 取消支付时保留订单（showPay 仍为 true），可重试
- 保留「取消」按钮调 order.php?action=cancel 关闭订单

### `pages/cart/cart.wxml`
- 底部按钮文案：「去结算」→「支付」
- 按钮状态：支付中显示「支付中...」
- 支付栏在 showPay=true 时仍可二次支付

### `pages/my/my.js`
- 后端登录返回时存 `openid` 到本地 userInfo

## 业务流程

```
购物车 → 点「支付 ¥X」
  ↓
后端 order.php?action=create → order_no
  ↓
后端 pay.php?action=prepay → {timeStamp, nonceStr, package, paySign, ...}
  ↓
wx.requestPayment(...)
  ├─ 成功 → 弹「支付成功」→ 重置购物车
  └─ 取消 → 弹「已取消支付」→ 保留订单可在「我的订单」重试
                ↓
              微信回调 pay_notify.php → 更新 status=paid
```

## 部署清单

1. 上传所有 PHP 文件到服务器
2. **必须**填写 `wechat_pay_config.php` 真实商户信息
3. 部署商户 API 证书（apiclient_key.pem）内容到配置
4. 部署平台证书（apiclient_cert.pem）到 `php/api/apiclient_cert.pem`（用于回调验签）
5. notify_url 需公网 HTTPS（已配 https://taozhi.433345.xyz/api/pay_notify.php）

## 注意事项
- 没有填写真实配置前，pay.php 会返回「微信支付未配置」错误
- 测试时建议先用沙箱 / 微信支付测试号
- 小程序必须用**正式 appid**（不能是测试号），否则无法调起支付
