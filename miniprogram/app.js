// app.js
const apiBase = 'https://taozhi.433345.xyz';
const amapWebKey = 'fb277f76881983b28400d76343d67374'; // 高德 Web 服务 API Key

App({
  globalData: {
    userInfo: null,
    cartList: [],
    store: null,
    apiBase: apiBase,
    amapWebKey: amapWebKey,
    // 订阅消息模板 ID（在微信公众平台申请后填入）
    // coupon = 优惠券领取成功提醒
    subscribeTemplates: {
      coupon: 'mov_hroYwggIBcnKTa43bRLMGNA70gbn2-k-VlLfq9A'  // 优惠券到账提醒
    }
  },

  onLaunch() {
    const cart = wx.getStorageSync('cartList');
    if (cart) {
      this.globalData.cartList = cart;
    }
    const userInfo = wx.getStorageSync('userInfo');
    if (userInfo) {
      this.globalData.userInfo = userInfo;
    }
  },

  /**
   * 获取页面分享配置（从后端拉取）
   * @param {string} pageKey 页面标识，如 'home' 'product' 'cart'
   * @returns {Promise<{share_title, share_img, page_name}>}
   */
  getShareConfig(pageKey) {
    return new Promise((resolve) => {
      // 5 分钟内不重复请求
      const cacheKey = '_share_' + pageKey;
      const cached = this.globalData[cacheKey];
      if (cached && Date.now() - cached._ts < 5 * 60 * 1000) {
        resolve(cached);
        return;
      }
      wx.request({
        url: apiBase + '/api/share.php',
        data: { page: pageKey },
        success: (res) => {
          if (res.data && res.data.code === 0 && res.data.data) {
            const cfg = {
              share_title: res.data.data.share_title || '',
              share_img: res.data.data.share_img || '',
              page_name: res.data.data.page_name || '',
              _ts: Date.now()
            };
            this.globalData[cacheKey] = cfg;
            resolve(cfg);
          } else {
            resolve({ share_title: '', share_img: '', page_name: '' });
          }
        },
        fail: () => resolve({ share_title: '', share_img: '', page_name: '' })
      });
    });
  },

  /**
   * 启用页面分享菜单（需在每个页面的 onLoad 中调用）
   * @param {string} pageKey 页面标识
   */
  enablePageShare(pageKey) {
    wx.showShareMenu({
      withShareTicket: true,
      menus: ['shareAppMessage', 'shareTimeline']
    });
    // 预热分享配置
    this.getShareConfig(pageKey);
  },

  saveCart() {
    wx.setStorageSync('cartList', this.globalData.cartList);
  },

  addToCart(product, count = 1) {
    const cart = this.globalData.cartList;
    const index = cart.findIndex(item => item.id === product.id);

    // 优先用活动价，原价兜底
    const price = product.sale_price || product.price;

    // 库存校验：购物车已有数量 + 本次新增数量 不得超过库存
    const currentCount = index > -1 ? cart[index].count : 0;
    const stock = product.stock || 0;
    if (stock > 0 && currentCount + count > stock) {
      wx.showToast({ title: '库存不足，最多加 ' + stock + ' 件', icon: 'none' });
      return false;
    }

    if (index > -1) {
      cart[index].count += count;
    } else {
      cart.push({...product, price, count});
    }

    this.globalData.cartList = cart;
    this.saveCart();
    return true;
  },

  getCartCount() {
    return this.globalData.cartList.reduce((sum, item) => sum + item.count, 0);
  },

  getCartTotal() {
    return this.globalData.cartList.reduce((sum, item) => {
      const p = item.sale_price || item.price || 0;
      return sum + p * item.count;
    }, 0);
  },

  // ==================== 高德地图工具 ====================

  /**
   * 地址与经纬度（地理编码）   * @param {string} address 地址
   * @returns {Promise<{lat: number, lng: number}>}
   */
  geocodeAddress(address) {
    return new Promise((resolve, reject) => {
      wx.request({
        url: `https://restapi.amap.com/v3/geocode/geo`,
        data: {
          key: this.globalData.amapWebKey,
          address: address,
          output: 'JSON'
        },
        success: (res) => {
          if (res.data && res.data.geocodes && res.data.geocodes.length > 0) {
            const loc = res.data.geocodes[0].location.split(',');
            resolve({
              lng: parseFloat(loc[0]),
              lat: parseFloat(loc[1])
            });
          } else {
            reject(new Error('地址解析失败' + (res.data?.info || '无结果')));
          }
        },
        fail: (err) => reject(err)
      });
    });
  },

  /**
   * 经纬度转地址（逆地理编码）
   * @param {number} lat
   * @param {number} lng
   * @returns {Promise<string>}
   */
  reverseGeocode(lat, lng) {
    return new Promise((resolve, reject) => {
      wx.request({
        url: `https://restapi.amap.com/v3/geocode/regeo`,
        data: {
          key: this.globalData.amapWebKey,
          location: `${lng},${lat}`,
          output: 'JSON'
        },
        success: (res) => {
          if (res.data && res.data.regeocode) {
            resolve(res.data.regeocode.formatted_address);
          } else {
            reject(new Error('逆地理编码失败'));
          }
        },
        fail: (err) => reject(err)
      });
    });
  },

  /**
   * 计算两点间距离   * @param {number} lat1
   * @param {number} lng1
   * @param {number} lat2
   * @param {number} lng2
   * @returns {Promise<number>} 距离（米）   */
  getDistance(lat1, lng1, lat2, lng2) {
    return new Promise((resolve, reject) => {
      wx.request({
        url: `https://restapi.amap.com/v3/distance`,
        data: {
          key: this.globalData.amapWebKey,
          origins: `${lng1},${lat1}`,
          destination: `${lng2},${lat2}`,
          type: 0  // 直线距离
        },
        success: (res) => {
          if (res.data && res.data.results && res.data.results.length > 0) {
            resolve(parseFloat(res.data.results[0].distance));
          } else {
            reject(new Error('距离计算失败'));
          }
        },
        fail: (err) => reject(err)
      });
    });
  }
})
