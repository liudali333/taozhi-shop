# 桃之 - 订单页移除状态筛选 (2026-07-05 12:21)

## 改动
- **order.wxml**: 移除顶部「全部/待付款/待发货/待收货/已完成」5 个 tab
- **order.js**: 移除 `tabs`、`currentTab`、`switchTab()` 等筛选相关代码，loadOrders 直接拉全部订单
- **order.wxss**: 清理 `.order-tabs`、`.tab-item`、`.tab-item.active` 样式

## 配套
- 之前已修改 my.wxml/my.js/my.wxss，「我的订单」区域只保留「全部订单」入口
- 现在 order 页面本身也不再按状态分组显示

## 效果
所有订单（不管待付款/待发货/待收货/已完成/已取消）都在一个列表里，
按时间倒序展示，订单状态用文字标签显示在每条订单右上角。
