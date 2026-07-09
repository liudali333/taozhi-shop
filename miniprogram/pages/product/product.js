const app = getApp();

Page({
  data: {
    product: {},
    cartCount: 0
  },

  onLoad(options) {
    app.enablePageShare('product');
    const id = options.id;
    if (id) this.loadProduct(id);
  },

  onShow() {
    this.setData({ cartCount: app.getCartCount() });
  },

  loadProduct(id) {
    wx.request({
      url: `${app.globalData.apiBase}/api/product.php`,
      data: { id },
      success: (res) => {
        if (res.data.code === 0) {
          const p = res.data.data;
          // 计算折扣金额（WXML不支持toFixed）
          p.discountAmount = (p.sale_price && p.sale_price < p.price)
            ? Math.round((p.price - p.sale_price) * 100) / 100
            : 0;
          // 解析详情图 JSON 数组
          let detailImages = [];
          try {
            detailImages = JSON.parse(p.description || '[]');
            if (!Array.isArray(detailImages)) detailImages = [];
          } catch (e) {}
          this.setData({ product: p, detailImages });
        } else {
          wx.showToast({ title: '商品不存在', icon: 'none' });
        }
      },
      fail: () => {
        wx.showToast({ title: '加载失败', icon: 'none' });
      }
    });
  },

  goHome() {
    wx.switchTab({ url: '/pages/home/home' });
  },

  goCart() {
    wx.navigateTo({ url: '/pages/cart/cart' });
  },

  addToCart() {
    if (this.data.product.stock <= 0) {
      wx.showToast({ title: '商品已售罄', icon: 'none' });
      return;
    }
    const ok = app.addToCart(this.data.product);
    if (ok) {
      this.setData({ cartCount: app.getCartCount() });
      wx.showToast({ title: '已加入购物车', icon: 'success' });
    }
  },

  buyNow() {
    if (this.data.product.stock <= 0) {
      wx.showToast({ title: '商品已售罄', icon: 'none' });
      return;
    }
    const ok = app.addToCart(this.data.product);
    if (ok) {
      wx.navigateTo({ url: '/pages/cart/cart' });
    }
  },

  // 发送给朋友（使用后台配置的分享标题/图片）
  onShareAppMessage() {
    const p = this.data.product;
    const cfg = app.globalData._share_product || {};
    const title = cfg.share_title || (p.name + (p.spec ? ' - ' + p.spec : ''));
    const imageUrl = cfg.share_img || p.image || '';
    return {
      title,
      path: '/pages/product/product?id=' + p.id,
      imageUrl
    };
  },

  // 分享到朋友圈
  onShareTimeline() {
    const p = this.data.product;
    const cfg = app.globalData._share_product || {};
    const title = cfg.share_title || (p.name + ' ¥' + p.sale_price);
    const imageUrl = cfg.share_img || p.image || '';
    return {
      title,
      imageUrl,
      query: 'id=' + p.id
    };
  }
})
