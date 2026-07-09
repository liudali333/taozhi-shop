# 模拟配送系统（测试用）

## 功能

后台订单管理页，配送订单可模拟完整骑手配送流程：

```
发配送 → 等待接单 → 骑手接单 → 到店 → 取货出发 → 模拟路线移动 → 送达 → 完成
```

## 后台操作

1. 进入订单管理，找到「已付款」的配送订单
2. 点击「🚴 发配送」→ 自动分配骑手「桃之 17622223333」，状态变为配送中
3. 订单卡片底部出现模拟配送按钮：
   - 📞 骑手接单
   - 📍 到店（骑手坐标移动到门店）
   - 🚴 取货出发
   - 🗺️ 模拟路线（每1.5秒移动一步，20步到达用户位置）
   - ✅ 完成订单（订单状态变为 completed）

## 数据库字段

| 字段 | 类型 | 说明 |
|------|------|------|
| `delivery_status` | VARCHAR(20) | waiting/accepted/at_store/delivering/delivered |
| `rider_name` | VARCHAR(64) | 骑手姓名 |
| `rider_phone` | VARCHAR(32) | 骑手电话 |
| `rider_lat` | DECIMAL(10,6) | 骑手纬度 |
| `rider_lng` | DECIMAL(10,6) | 骑手经度 |

## 小程序端

订单详情页会读取 `rider_name`、`rider_phone`、`rider_lat`、`rider_lng`、`delivery_status` 字段，显示骑手信息和实时位置。

## 文件

| 文件 | 说明 |
|------|------|
| `php/admin/orders.php` | 后台订单管理，含模拟配送按钮 |
| `php/api/delivery_sim.php` | 模拟配送 API |

## 后续

- 聚合平台 API 接入后，替换模拟配送为真实骑手数据
- 小程序端地图实时追踪骑手位置
