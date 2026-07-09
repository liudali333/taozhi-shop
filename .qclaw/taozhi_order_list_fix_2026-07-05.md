# 桃之 - 订单列表无法显示修复 (2026-07-05 12:42)

## 诊断：4 个问题

### 1. `item.products` → `item.items` 字段不一致
- `order.php` 用 `items` 字段存储商品（JSON），返回 `o['items']` 
- `order.wxml` 渲染 `wx:for="{{item.products}}"` → 永远为空 → 显示「暂无订单」
- **修复**：`order.js` `loadOrders()` 添加映射 `products: o.items || o.products`

### 2. `data-id` 传的是数据库 auto-increment id，不是 order_no
- 取消/支付/确认收货都需要 `order_no`（业务订单号）
- WXML 只传了 `data-id="{{item.id}}"`（数据库自增 id），后端路由认 `order_no`
- **修复**：WXML 按钮加 `data-no="{{item.order_no}}"`，JS 优先用 `dataset.no`

### 3. `update_status` 后端硬编码
- `order.php?action=update_status` 总是 set status='cancelled'，忽略 `?status=completed`
- **修复**：读取 `$input['status']` + 状态机校验（pending→paid/cancelled, paid→shipped/cancelled, shipped→completed）

### 4. 缺加载/错误状态
- `loadOrders()` 无 `fail` 回调 → 网络错误完全静默
- **修复**：加 `fail` 回调弹 Toast，加 `loading` 状态，WXML 加 loading 提示

## 修改文件
| 文件 | 改动 |
|------|------|
| `miniprogram/pages/order/order.js` | 全量重写：字段映射、data-no 支持、loading 状态、fail 回调、payOrder 传 order_no |
| `miniprogram/pages/order/order.wxml` | 按钮加 data-no、加 loading 状态分支 |
| `miniprogram/pages/order/order.wxss` | 加 `.loading-state` 样式 |
| `php/api/order.php` | `update_status` 读 status 参数 + 状态机校验 |

## 仍需用户检查
1. 部署新代码到服务器后，打开「我的订单」页
2. 看 Console 日志输出 `[订单] user_id: xxx` 和 `[订单] 响应: {...}`
3. 如果 user_id 为 0 或 undefined：说明 localStorage 的 userInfo 没有 id
4. 如果后端返回空数组 `[]`：说明该用户 ID 下确实无订单（可能是 localLogin 和真实登录 ID 不一致）
