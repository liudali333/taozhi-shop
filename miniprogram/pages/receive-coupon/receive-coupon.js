const app = getApp();

Page({
  data: {
    couponId: 0,
    coupon: null,
    loading: true,
    error: '',
    received: false,
    autoClaim: false,   // true: 来自二维码 → 自动领取
    claiming: false,    // 领取进行中
    subscribed: false,  // 已询问过订阅授权
  },

  onLoad(options) {
    // ===== 解析 scene（支持多种格式）=====
    // 场景1: 普通链接 → ?id=123&from=qr
    // 场景2: 小程序码 → ?scene=id_123_qr  或  scene=id%5F123%5Fqr
    // 场景3: 微信开发者工具模拟扫码 → scene 直接是字符串
    let id = options.id || options.coupon_id;
    let from = options.from || '';

    console.log('[receive-coupon] onLoad options:', JSON.stringify(options));

    if (!id && options.scene) {
      // 微信对 scene 做了 URL-encode，decode 后应该能拿到原始值
      const raw = options.scene;
      let scene = raw;
      try { scene = decodeURIComponent(raw); } catch(e) {}
      console.log('[receive-coupon] scene decoded:', scene);

      // 兼容多种格式：id_5_qr, id_5, 5_qr, id%5F5%5Fqr, 5 等
      let m = scene.match(/^id[_\-]?(\d+)(?:[_\-]?qr)?$/i);   // id_5_qr / id-5-qr
      if (!m) m = scene.match(/^(\d+)(?:[_\-]?qr)?$/i);          // 5_qr / 5
      if (!m) m = scene.match(/[?&]?id[_=]?(\d+)/i);             // id=5 或 ?id=5
      if (!m) m = scene.match(/_(\d+)_qr$/);                     // _5_qr 结尾

      if (m) {
        id = m[1];
        from = 'qr';
        console.log('[receive-coupon] scene 解析成功，couponId:', id);
      } else {
        console.error('[receive-coupon] scene 格式无法识别:', scene, '，原始值:', raw);
      }
    }

    if (!id) {
      // 展示更详细的错误信息，方便排查
      const detail = options.scene ? ('scene=' + options.scene) : '无scene参数';
      this.setData({ loading: false, error: '缺少优惠券参数（' + detail + '）' });
      return;
    }

    this.setData({
      couponId: parseInt(id) || 0,
      autoClaim: from === 'qr',
    });

    this.loadCoupon();
  },

  onShow() {
    // 从登录页回来后再尝试一次自动领取
    if (this.data.autoClaim && !this.data.received && !this.data.claiming) {
      this.tryAutoClaim();
    }
  },

  loadCoupon() {
    this.setData({ loading: true, error: '' });

    wx.request({
      url: `${app.globalData.apiBase}/api/coupon.php?action=detail`,
      data: { id: this.data.couponId },
      success: (res) => {
        if (res.data.code === 0 && res.data.data) {
          this.setData({ coupon: res.data.data, loading: false });
          // 加载完后启动自动领取
          if (this.data.autoClaim) {
            this.tryAutoClaim();
          }
        } else {
          this.setData({ error: res.data.msg || '优惠券不存在', loading: false });
        }
      },
      fail: () => {
        this.setData({ error: '加载失败，请重试', loading: false });
      }
    });
  },

  retry() {
    this.loadCoupon();
  },

  // ===== 自动领取（来自二维码时调用）=====
  tryAutoClaim() {
    if (this.data.claiming || this.data.received) return;

    const userInfo = wx.getStorageSync('userInfo');
    if (!userInfo || !userInfo.id) {
      // 未登录 → 跳到「我的」登录
      wx.showModal({
        title: '需要登录',
        content: '领取优惠券需要先登录，是否前往？',
        confirmText: '去登录',
        success: (r) => {
          if (r.confirm) {
            wx.navigateTo({ url: '/pages/my/my' });
          }
        }
      });
      return;
    }

    this.setData({ claiming: true });
    wx.showLoading({ title: '领取中...', mask: true });

    wx.request({
      url: `${app.globalData.apiBase}/api/coupon.php?action=receive`,
      method: 'POST',
      data: {
        user_id: userInfo.id,
        coupon_id: this.data.couponId,
        from: 'qr',
      },
      success: (res) => {
        wx.hideLoading();
        this.setData({ claiming: false });
        if (res.data.code === 0) {
          this.setData({ received: true });
          wx.showToast({ title: '领取成功', icon: 'success' });
          // 领取成功后弹订阅授权
          this.requestSubscribe();
        } else {
          // 失败也展示券详情，让用户重试
          wx.showToast({ title: res.data.msg || '领取失败', icon: 'none' });
        }
      },
      fail: () => {
        wx.hideLoading();
        this.setData({ claiming: false });
        wx.showToast({ title: '网络错误', icon: 'none' });
      }
    });
  },

  // ===== 订阅消息授权 =====
  requestSubscribe() {
    if (this.data.subscribed) return;
    const tplId = (app.globalData.subscribeTemplates || {})['coupon'] || '';
    if (!tplId) return;   // 未配置则不弹

    this.setData({ subscribed: true });
    wx.requestSubscribeMessage({
      tmplIds: [tplId],
      success: (res) => {
        // 用户授权后，服务端在下次领取时会自动发送通知
        console.log('[subscribe] result:', res);
      },
      fail: (err) => {
        console.log('[subscribe] fail:', err);
      }
    });
  },

  // ===== 手动领取按钮（用户主动从其他入口进入时）=====
  receiveCoupon() {
    if (this.data.claiming) return;
    const userInfo = wx.getStorageSync('userInfo');
    if (!userInfo || !userInfo.id) {
      wx.showToast({ title: '请先登录', icon: 'none' });
      setTimeout(() => wx.navigateTo({ url: '/pages/my/my' }), 1500);
      return;
    }

    this.setData({ claiming: true });
    wx.showLoading({ title: '领取中...', mask: true });

    wx.request({
      url: `${app.globalData.apiBase}/api/coupon.php?action=receive`,
      method: 'POST',
      data: { user_id: userInfo.id, coupon_id: this.data.couponId },
      success: (res) => {
        wx.hideLoading();
        this.setData({ claiming: false });
        if (res.data.code === 0) {
          this.setData({ received: true });
          wx.showToast({ title: '领取成功', icon: 'success' });
          this.requestSubscribe();
        } else {
          wx.showToast({ title: res.data.msg || '领取失败', icon: 'none' });
        }
      },
      fail: () => {
        wx.hideLoading();
        this.setData({ claiming: false });
        wx.showToast({ title: '网络错误', icon: 'none' });
      }
    });
  },

  goUse() {
    wx.switchTab({ url: '/pages/home/home' });
  }
});
