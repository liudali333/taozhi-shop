const app = getApp();

Page({
  data: {
    statusText: {
      pending: '待付款',
      paid: '待发货',
      preparing: '备货中',
      delivering: '配送中',
      shipped: '待收货',
      completed: '已完成',
      cancelled: '已取消'
    },
    orders: [],
    loading: false
  },

  onLoad() {},

  goDetail(e) {
    const orderNo = e.currentTarget.dataset.no || e.currentTarget.dataset.id;
    if (!orderNo) return;
    wx.navigateTo({ url: '/pages/order-detail/order-detail?order_no=' + orderNo });
  },

  onShow() {
    this.loadOrders();
  },

  loadOrders() {
    const userInfo = wx.getStorageSync('userInfo') || {};
    // 兼容 localLogin 的数字型 id 和后端返回的 auto_increment id
    const userId = userInfo.id || 0;

    if (!userId) {
      console.warn('[订单] userId 为空，userInfo:', userInfo);
      wx.showToast({ title: '请先登录', icon: 'none' });
      return;
    }

    this.setData({ loading: true });
    console.log('[订单] 准备加载，userId:', userId);

    wx.request({
      url: `${app.globalData.apiBase}/api/order.php`,
      data: { user_id: userId },
      success: (res) => {
        console.log('[订单] HTTP状态:', res.statusCode, '原始响应:', JSON.stringify(res.data));
        this.setData({ loading: false });
        if (res.data.code === 0) {
          // 映射 items -> products 兼容旧模板
          const orders = (res.data.data || []).map(o => ({
            ...o,
            products: o.items || o.products || [],
            create_time: o.created_at || o.create_time || '',
            total_count: (o.items || []).reduce((sum, item) => sum + (item.count || 1), 0)
          }));
          this.setData({ orders });
        } else {
          console.warn('[订单] 接口失败:', res.data.msg);
        }
      },
      fail: (err) => {
        this.setData({ loading: false });
        console.error('[订单] 请求失败:', err);
        wx.showToast({ title: '网络错误', icon: 'none' });
      }
    });
  },

  cancelOrder(e) {
    const orderNo = e.currentTarget.dataset.no || e.currentTarget.dataset.id;
    wx.showModal({
      title: '提示',
      content: '确定要取消订单吗？',
      success: (res) => {
        if (res.confirm) {
          wx.showLoading({ title: '取消中...' });
          wx.request({
            url: `${app.globalData.apiBase}/api/order.php?action=cancel`,
            method: 'POST',
            data: { order_no: orderNo },
            success: (res) => {
              wx.hideLoading();
              if (res.data.code === 0) {
                wx.showToast({ title: '已取消', icon: 'success' });
                this.loadOrders();
              } else {
                wx.showToast({ title: res.data.msg || '取消失败', icon: 'none' });
              }
            },
            fail: () => {
              wx.hideLoading();
              wx.showToast({ title: '网络错误', icon: 'none' });
            }
          });
        }
      }
    });
  },

  payOrder(e) {
    const orderNo = e.currentTarget.dataset.no || e.currentTarget.dataset.id;
    if (!orderNo) return;

    const userInfo = wx.getStorageSync('userInfo');
    const openid = (userInfo && userInfo.openid) || '';

    wx.showLoading({ title: '发起支付...' });

    wx.request({
      url: `${app.globalData.apiBase}/api/pay.php?action=prepay`,
      method: 'POST',
      data: { order_no: orderNo, openid: openid },
      success: (res) => {
        wx.hideLoading();
        if (res.data.code === 0) {
          const payData = res.data.data;
          wx.requestPayment({
            timeStamp: payData.timeStamp,
            nonceStr: payData.nonceStr,
            package: payData.package,
            signType: payData.signType || 'RSA',
            paySign: payData.paySign,
            success: () => {
              wx.showToast({ title: '支付成功', icon: 'success' });
              this.loadOrders();
            },
            fail: (err) => {
              console.log('支付失败', err);
              wx.showToast({ title: err.errMsg.includes('cancel') ? '已取消支付' : '支付失败', icon: 'none' });
            }
          });
        } else {
          wx.showToast({ title: res.data.msg || '获取支付信息失败', icon: 'none' });
        }
      },
      fail: () => {
        wx.hideLoading();
        wx.showToast({ title: '网络错误', icon: 'none' });
      }
    });
  },

  confirmReceive(e) {
    const orderNo = e.currentTarget.dataset.no || e.currentTarget.dataset.id;
    wx.request({
      url: `${app.globalData.apiBase}/api/order.php?action=update_status`,
      method: 'POST',
      data: { order_no: orderNo, status: 'completed' },
      success: (res) => {
        if (res.data.code === 0) {
          wx.showToast({ title: '已确认收货', icon: 'success' });
          this.loadOrders();
        }
      }
    });
  }
})
