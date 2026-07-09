const app = getApp();

Page({
  data: {
    order: null,
    loading: true,
    statusText: {
      pending: '待付款',
      accepted: '商家已接单',
      dispatched: '正在召唤骑手',
      picked_up: '骑手已接单',
      delivered: '骑手已取货',
      completed: '已完成',
      cancelled: '已取消',
    },
    statusHint: {
      pending: '请尽快完成支付',
      accepted: '商家已接单，请等待分配骑手',
      dispatched: '骑手正在接单中，请稍候',
      picked_up: '骑手已接单，正在前往门店',
      delivered: '骑手正在配送中，请保持手机畅通',
      completed: '感谢您的购买，欢迎再次光临',
      cancelled: '订单已取消',
    },
    mapLat: 0,
    mapLng: 0,
    markers: [],
    polyline: [],
    distance: '',
    timelineSteps: [],
    pickupCode: '',
    riderTag: '',
  },

  onLoad(options) {
    const { order_no } = options;
    if (order_no) {
      this.loadDetail(order_no);
    } else {
      this.setData({ loading: false });
    }
  },

  loadDetail(orderNo) {
    this.setData({ loading: true });
    const cacheKey = 'order_' + orderNo;
    const cached = app.globalData._orderCache?.[cacheKey];
    if (cached) {
      this._buildPageData(cached);
      this.setData({ loading: false });
      return;
    }
    wx.request({
      url: `${app.globalData.apiBase}/api/order.php?action=detail&order_no=${orderNo}`,
      success: (res) => {
        this.setData({ loading: false });
        if (res.data.code === 0) {
          this._buildPageData(res.data.data);
        } else {
          wx.showToast({ title: res.data.msg || '加载失败', icon: 'none' });
        }
      },
      fail: () => {
        this.setData({ loading: false });
        wx.showToast({ title: '网络错误', icon: 'none' });
      },
    });
  },

  _buildPageData(order) {
    const pickupCode = order.delivery_type === 'self' ? (order.serial_no || '') : '';
    const storeLat = order.store_lat || 0;
    const storeLng = order.store_lng || 0;
    const userLat = order.user_lat || 0;
    const userLng = order.user_lng || 0;
    const riderLat = order.rider_lat || 0;
    const riderLng = order.rider_lng || 0;

    let mapLat = userLat || storeLat;
    let mapLng = userLng || storeLng;

    const markers = [];
    if (storeLat && storeLng) {
      markers.push({
        id: 'store',
        latitude: storeLat,
        longitude: storeLng,
        iconPath: '/images/marker-store.png',
        width: 32,
        height: 40,
        callout: { content: order.store_name || '商家', color: '#333', fontSize: 12, borderRadius: 6, padding: 6, bgColor: '#fff', display: 'ALWAYS' },
      });
    }
    if (userLat && userLng) {
      markers.push({
        id: 'user',
        latitude: userLat,
        longitude: userLng,
        iconPath: '/images/marker-user.png',
        width: 28,
        height: 36,
        callout: { content: '收货地', color: '#333', fontSize: 12, borderRadius: 6, padding: 6, bgColor: '#fff', display: 'ALWAYS' },
      });
    }
    // 骑手坐标有值且不是初始状态，就显示标记
    if (riderLat && riderLng && order.delivery_status !== 'accepted') {
      markers.push({
        id: 'rider',
        latitude: riderLat,
        longitude: riderLng,
        iconPath: '/images/marker-rider.png',
        width: 28,
        height: 36,
        callout: { content: order.rider_name || '骑手', color: '#333', fontSize: 12, borderRadius: 6, padding: 6, bgColor: '#fff', display: 'ALWAYS' },
      });
    }

    let polyline = [];
    let distance = '';
    if (storeLat && storeLng && userLat && userLng) {
      if (riderLat && riderLng && order.delivery_status !== 'accepted') {
        polyline = [{ points: [{ latitude: storeLat, longitude: storeLng }, { latitude: riderLat, longitude: riderLng }, { latitude: userLat, longitude: userLng }], color: '#ff9800', width: 3, dottedLine: false }];
      } else {
        polyline = [{ points: [{ latitude: storeLat, longitude: storeLng }, { latitude: userLat, longitude: userLng }], color: '#e64340', width: 3, dottedLine: false }];
      }
      const d = this._calcDistance(storeLat, storeLng, userLat, userLng);
      distance = d > 1 ? d.toFixed(1) + 'km' : (d * 1000).toFixed(0) + 'm';
    }

    const timelineSteps = this._buildTimeline(order);

    this.setData({
      order,
      pickupCode,
      mapLat,
      mapLng,
      markers,
      polyline,
      distance,
      timelineSteps,
      riderTag: order.rider_name ? `骑手：${order.rider_name} ${order.rider_phone || ''}` : '',
    });
  },

  _buildTimeline(order) {
    const fmt = (str) => str ? str.replace('T', ' ').slice(0, 16) : '';
    const now = fmt(new Date().toISOString());
    const ds = order.delivery_status || 'accepted';

    const statusIdx = { accepted: 0, dispatched: 1, picked_up: 2, delivered: 3, completed: 4 };
    const idx = statusIdx[ds] ?? 0;

    if (order.status === 'cancelled') {
      return [{ key: 'cancelled', label: '已取消', time: now, past: false, current: true, color: 'gray' }];
    }

    if (order.delivery_type === 'self') {
      if (order.status === 'completed') {
        return [
          { key: 'ready', label: '备货完成', time: fmt(order.paid_at), past: true, current: false, color: 'green' },
          { key: 'picked', label: '已取货', time: fmt(order.delivery_time), past: true, current: false, color: 'green' },
        ];
      }
      if (order.status === 'paid') {
        return [{ key: 'ready', label: '备货完成', time: fmt(order.paid_at), past: false, current: true, color: 'orange' }];
      }
      return [];
    }

    const steps = [
      { key: 'merchant_accepted', label: '商家已接单', color: 'green' },
      { key: 'calling_rider', label: '正在召唤骑手', color: 'orange' },
      { key: 'rider_accepted', label: '骑手已接单', color: 'orange' },
      { key: 'rider_picked', label: '骑手已取货', color: 'orange' },
      { key: 'completed', label: '已完成', color: 'green' },
    ];

    return steps.map((step, i) => {
      const isPast = i < idx;
      const isCurrent = i === idx;
      return { ...step, time: isPast ? now : (isCurrent ? now : ''), past: isPast, current: isCurrent };
    });
  },

  _calcDistance(lat1, lng1, lat2, lng2) {
    const R = 6371;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLng = (lng2 - lng1) * Math.PI / 180;
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  },

  payOrder() {
    const { order } = this.data;
    if (!order) return;
    wx.redirectTo({ url: `/pages/order-pay/order-pay?order_no=${order.order_no}` });
  },

  confirmReceive() {
    const { order } = this.data;
    if (!order) return;
    wx.showModal({
      title: '确认收货',
      content: '请确认您已收到商品',
      success: (res) => {
        if (res.confirm) {
          wx.request({
            url: `${app.globalData.apiBase}/api/order.php?action=complete`,
            method: 'POST',
            data: { order_no: order.order_no },
            success: (r) => {
              if (r.data.code === 0) {
                wx.showToast({ title: '收货成功', icon: 'success' });
                this.loadDetail(order.order_no);
              } else {
                wx.showToast({ title: r.data.msg || '操作失败', icon: 'none' });
              }
            },
          });
        }
      },
    });
  },

  cancelOrder() {
    const { order } = this.data;
    if (!order) return;
    wx.showModal({
      title: '取消订单',
      content: '确定要取消该订单吗？',
      success: (res) => {
        if (res.confirm) {
          wx.request({
            url: `${app.globalData.apiBase}/api/order.php?action=cancel`,
            method: 'POST',
            data: { order_no: order.order_no },
            success: (r) => {
              if (r.data.code === 0) {
                wx.showToast({ title: '已取消', icon: 'success' });
                this.loadDetail(order.order_no);
              } else {
                wx.showToast({ title: r.data.msg || '操作失败', icon: 'none' });
              }
            },
          });
        }
      },
    });
  },

  refreshRider() {
    const { order } = this.data;
    if (order && order.order_no) {
      this.loadDetail(order.order_no);
    }
  },

  viewMap() {
    const { mapLat, mapLng, markers } = this.data;
    if (!mapLat || !mapLng) {
      wx.showToast({ title: '暂无坐标', icon: 'none' });
      return;
    }
    wx.openLocation({ latitude: mapLat, longitude: mapLng, name: '配送地点', scale: 16 });
  },

  copyCode() {
    const { pickupCode } = this.data;
    if (!pickupCode) return;
    wx.setClipboardData({ data: pickupCode, success: () => wx.showToast({ title: '已复制', icon: 'success' }) });
  },

  callRider() {
    const { order } = this.data;
    if (!order || !order.rider_phone) {
      wx.showToast({ title: '暂无骑手电话', icon: 'none' });
      return;
    }
    wx.makePhoneCall({ phoneNumber: order.rider_phone });
  },
});
