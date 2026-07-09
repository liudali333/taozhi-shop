const app = getApp();

Page({
  data: {
    tab: 'usable',
    list: [],
    loading: false
  },

  onLoad() {
    app.enablePageShare('coupons');
    this.loadCoupons();
  },

  onShow() {
    this.loadCoupons();
  },

  switchTab(e) {
    const tab = e.currentTarget.dataset.tab;
    if (tab === this.data.tab) return;
    this.setData({ tab, list: [] });
    this.loadCoupons();
  },

  loadCoupons() {
    const userInfo = wx.getStorageSync('userInfo');
    if (!userInfo || !userInfo.id) {
      wx.showToast({ title: '请先登录', icon: 'none' });
      return;
    }

    this.setData({ loading: true });

    wx.request({
      url: `${app.globalData.apiBase}/api/coupon.php?action=my_list`,
      data: { user_id: userInfo.id, status: this.data.tab },
      success: (res) => {
        if (res.data.code === 0) {
          this.setData({ list: res.data.data || [] });
        }
      },
      fail: () => {
        wx.showToast({ title: '加载失败', icon: 'none' });
      },
      complete: () => {
        this.setData({ loading: false });
      }
    });
  },

  onShareAppMessage() {
    const cfg = app.globalData._share_coupons || {};
    return {
      title: cfg.share_title || '领券中心 - 桃之',
      path: '/pages/coupon/coupon',
      imageUrl: cfg.share_img || ''
    };
  },

  onShareTimeline() {
    const cfg = app.globalData._share_coupons || {};
    return {
      title: cfg.share_title || '领券中心 - 桃之',
      imageUrl: cfg.share_img || '',
      query: ''
    };
  }
});
