const app = getApp();

Page({
  data: {
    store: null,
    storeName: '加载中...',
    cartList: [],
    deliveryType: 'self',
    remark: '',
    totalPrice: '0.00',
    deliveryFee: 0,
    minOrderAmount: 0,
    zoneName: '',
    minMet: true,
    diffAmount: '0.00',
    // 营业状态
isOpen: true,
    openTime: '09:00',
    closeTime: '22:00',
    // 配送状态
canDeliver: null,
    deliveryRadius: 5,
    userLat: 0,
    userLng: 0,
    // 提示
    notice: '',
    finalPrice: '0.00',
    // 地址
    selectedAddress: null,
    // 支付状态
showPay: false,
    orderId: null,
    orderNo: null,
    payAmount: '0.00',
    paying: false
  },
  
  onLoad() {
    app.enablePageShare('cart');
    this.setData({
      deliveryType: wx.getStorageSync('deliveryType') || 'self'
    });
    this.loadStoreInfo();
    this.loadDefaultAddress();
  },
  
  onShow() {
    this.loadCart();
    this.getUserLocation();
  },

  // 加载默认地址
  loadDefaultAddress() {
    // 如果从地址选择页返回时携带了地址，不覆盖
    // onShow 覆盖
    if (this.data.selectedAddress) return;

    const addressList = wx.getStorageSync('addressList') || [];
    const defaultAddr = addressList.find(a => a.isDefault) || addressList[0] || null;
    this.setData({ selectedAddress: defaultAddr });
    
    // 如果有默认地址，根据地址坐标检测配送范围
    if (defaultAddr && defaultAddr.lat && defaultAddr.lng) {
      this.setData({
        userLat: defaultAddr.lat,
        userLng: defaultAddr.lng
      });
      this.checkDeliveryRange(defaultAddr.lat, defaultAddr.lng);
    }
  },

  // 从地址选择页返回后的回调
  onAddressSelected(address) {
    this.setData({ selectedAddress: address });
    wx.setStorageSync('selectedCartAddress', address);
    
    if (address.lat && address.lng) {
      this.setData({
        userLat: address.lat,
        userLng: address.lng
      });
      this.checkDeliveryRange(address.lat, address.lng);
    }
  },
  
  // 加载门店配置
  loadStoreInfo() {
    wx.request({
      url: `${app.globalData.apiBase}/api/store.php`,
      success: (res) => {
        if (res.data.code === 0) {
          const store = res.data.data;
          this.setData({
            store,
            storeName: store.name,
            openTime: store.open_time,
            closeTime: store.close_time,
            notice: store.notice || ''
          });
          app.globalData.store = store;
          this.checkBusinessHours(store);
        }
      }
    });
  },
  
  // 获取用户位置（兼容旧逻辑：如果没选地址则用定位）
  getUserLocation() {
    // 如果已经有地址了，不重复获取定位
    if (this.data.selectedAddress && this.data.selectedAddress.lat) return;

    wx.getLocation({
      type: 'gcj02',
      success: (res) => {
        this.setData({
          userLat: res.latitude,
          userLng: res.longitude
        });
        this.checkDeliveryRange(res.latitude, res.longitude);
      },
      fail: () => {
        this.setData({ canDeliver: null });
      }
    });
  },
  
  // 检查营业状态
checkBusinessHours(store) {
    const now = new Date();
    const openParts = store.open_time.split(':');
    const closeParts = store.close_time.split(':');
    const openMinutes = parseInt(openParts[0]) * 60 + parseInt(openParts[1]);
    const closeMinutes = parseInt(closeParts[0]) * 60 + parseInt(closeParts[1]);
    const nowMinutes = now.getHours() * 60 + now.getMinutes();
    
    const isOpen = nowMinutes >= openMinutes && nowMinutes < closeMinutes;
    this.setData({ isOpen });
  },
  
  // 检查配送范围 - 使用后端API检查多配送区域
  checkDeliveryRange(lat, lng) {
    wx.request({
      url: `${app.globalData.apiBase}/api/store.php?action=check&lat=${lat}&lng=${lng}`,
      success: (res) => {
        if (res.data.code === 0) {
          const result = res.data.data;
          const canDeliver = result.can_deliver;
          const zone = result.zone;
          
          this.setData({
            canDeliver: canDeliver,
            deliveryFee: zone ? zone.delivery_fee : 0,
            minOrderAmount: zone ? zone.min_order_amount : 0,
            zoneName: zone ? zone.name : '',
            deliveryRadius: result.distance_text || ''
          });
          
          // 重新计算配送费与起送价
          this.recompute();
          
          if (!canDeliver && this.data.deliveryType === 'delivery') {
            this.setData({ deliveryType: 'self' });
            wx.setStorageSync('deliveryType', 'self');
            wx.showToast({
              title: '地址超出配送范围，已切换为自提',
              icon: 'none',
              duration: 2500
            });
          }
        }
      },
      fail: () => {
        // 网络失败时默认允许配送
        this.setData({ canDeliver: true });
      }
    });
  },
  
  // 加载购物车
loadCart() {
    const cartList = app.globalData.cartList;
    const totalPrice = app.getCartTotal();
    this.setData({
      cartList,
      totalPrice: Number(totalPrice).toFixed(2)
    });
    this.recompute();
  },

  // 重新计算配送费、最终价、起送校验
  recompute() {
    const total = Number(this.data.totalPrice) || 0;
    const isDelivery = this.data.deliveryType === 'delivery';
    const deliveryFee = isDelivery ? Number(this.data.deliveryFee) : 0;
    const finalPrice = (total + deliveryFee).toFixed(2);

    const minOrder = Number(this.data.minOrderAmount) || 0;
    const minMet = !isDelivery || minOrder <= 0 || total >= minOrder;
    const diffAmount = minMet ? '0.00' : (minOrder - total).toFixed(2);

    this.setData({
      finalPrice,
      minMet,
      diffAmount
    });
  },
  
  // 选择配送方式
selectDelivery(e) {
    const type = e.currentTarget.dataset.type;
    
    if (type === 'delivery' && this.data.canDeliver === false) {
      wx.showToast({ title: '地址超出配送范围', icon: 'none' });
      return;
    }
    
    // 配送需先选地址
    if (type === 'delivery' && !this.data.selectedAddress) {
      wx.showToast({ title: '请先添加收货地址', icon: 'none' });
      return;
    }
    
    const deliveryFee = type === 'delivery' ? Number(this.data.deliveryFee) : 0;
    const total = Number(this.data.totalPrice) || 0;
    this.setData({
      deliveryType: type,
      finalPrice: (total + deliveryFee).toFixed(2)
    });
    wx.setStorageSync('deliveryType', type);
    this.recompute();
  },
  
  inputRemark(e) {
    this.setData({ remark: e.detail.value });
  },
  
  increase(e) {
    const id = e.currentTarget.dataset.id;
    const cart = app.globalData.cartList;
    const index = cart.findIndex(item => item.id === id);
    if (index > -1) {
      const stock = cart[index].stock || 0;
      if (stock > 0 && cart[index].count >= stock) {
        wx.showToast({ title: '库存不足，最多' + stock + ' 件', icon: 'none' });
        return;
      }
      cart[index].count++;
      app.globalData.cartList = cart;
      app.saveCart();
      this.loadCart();
    }
  },
  
  decrease(e) {
    const id = e.currentTarget.dataset.id;
    const cart = app.globalData.cartList;
    const index = cart.findIndex(item => item.id === id);
    if (index > -1) {
      if (cart[index].count > 1) {
        cart[index].count--;
        app.globalData.cartList = cart;
        app.saveCart();
        this.loadCart();
      } else {
        this.deleteItem(e);
      }
    }
  },
  
  deleteItem(e) {
    const id = e.currentTarget.dataset.id;
    const cart = app.globalData.cartList.filter(item => item.id !== id);
    app.globalData.cartList = cart;
    app.saveCart();
    this.loadCart();
  },
  
  goHome() {
    wx.switchTab({ url: '/pages/home/home' });
  },

  // 跳转到地址页
goAddress() {
    wx.navigateTo({ url: '/pages/address/address' });
  },
  
  // 计算最终金额
getFinalPrice() {
    const total = parseFloat(this.data.totalPrice);
    const deliveryFee = this.data.deliveryType === 'delivery' ? Number(this.data.deliveryFee) : 0;
    return (Number(total) + deliveryFee).toFixed(2);
  },
  
  // 一键支付：提交订单 + 调起微信支付
  goPay() {
    if (this.data.paying) return;
    
    // 检查营业状态
if (!this.data.isOpen) {
      wx.showToast({
        title: `营业时间 ${this.data.openTime}-${this.data.closeTime}`,
        icon: 'none',
        duration: 3000
      });
      return;
    }
    
    // 检查地址
    if (this.data.deliveryType === 'delivery' && !this.data.selectedAddress) {
      wx.showToast({ title: '请先添加收货地址', icon: 'none' });
      return;
    }
    
    // 检查配送范围
if (this.data.deliveryType === 'delivery' && this.data.canDeliver === false) {
      wx.showToast({ title: '地址超出配送范围', icon: 'none' });
      return;
    }
    
    // 检查起送价
if (this.data.deliveryType === 'delivery' && !this.data.minMet) {
      wx.showToast({ title: `还差¥${this.data.diffAmount}起送`, icon: 'none' });
      return;
    }
    
    // 检查登录
const userInfo = wx.getStorageSync('userInfo');
    if (!userInfo) {
      wx.showToast({ title: '请先登录', icon: 'none' });
      return;
    }
    if (!userInfo.openid) {
      wx.showModal({
        title: '需要重新登录',
        content: '登录信息已过期，请退出后重新登录',
        showCancel: false,
        success: () => {
          wx.switchTab({ url: '/pages/my/my' });
        }
      });
      return;
    }
    
    const store = this.data.store;
    const address = this.data.selectedAddress;
    
    const orderData = {
      user_id: userInfo.id,
      store_id: store.id,
      store_name: store.name,
      products: app.globalData.cartList,
      delivery_type: this.data.deliveryType,
      remark: this.data.remark,
      total_price: this.getFinalPrice(),
      delivery_fee: this.data.deliveryType === 'delivery' ? this.data.deliveryFee : 0,
      user_lat: address ? address.lat : this.data.userLat,
      user_lng: address ? address.lng : this.data.userLng,
      consignee_name: address ? address.name : '',
      consignee_phone: address ? address.phone : '',
      consignee_address: address ? (address.region + ' ' + address.detail) : ''
    };
    
    this.setData({ paying: true });
    wx.showLoading({ title: '提交订单...' });
    
    // 第1步：创建订单
    wx.request({
      url: `${app.globalData.apiBase}/api/order.php?action=create`,
      method: 'POST',
      data: orderData,
      success: (createRes) => {
        if (createRes.data.code !== 0) {
          this.setData({ paying: false });
          wx.hideLoading();
          wx.showToast({ title: createRes.data.msg || '下单失败', icon: 'none' });
          return;
        }
        
        const orderNo = createRes.data.order_no || createRes.data.data.order_no;
        const orderId = createRes.data.data.order_id;
        const payAmount = this.data.finalPrice;
        const openid = userInfo.openid || '';
        
        // 清空购物车
app.globalData.cartList = [];
        app.saveCart();
        
        // 准备支付参数
        this.setData({
          showPay: true,
          orderId: orderId,
          orderNo: orderNo,
          payAmount: payAmount,
          cartList: [],
          totalPrice: '0.00',
          finalPrice: '0.00',
          remark: ''
        });
        
        // 第2步：调用后端获取调起支付参数
        wx.showLoading({ title: '唤起微信支付...' });
        this.requestPrepay(orderNo, openid);
      },
      fail: () => {
        this.setData({ paying: false });
        wx.hideLoading();
        wx.showToast({ title: '网络错误', icon: 'none' });
      }
    });
  },

  // 获取调起支付参数
  requestPrepay(orderNo, openid) {
    wx.request({
      url: `${app.globalData.apiBase}/api/pay.php?action=prepay`,
      method: 'POST',
      data: { order_no: orderNo, openid: openid },
      success: (res) => {
        wx.hideLoading();
        if (res.data.code === 0) {
          const payData = res.data.data;
          // 第3步：调起微信支付
          wx.requestPayment({
            timeStamp: payData.timeStamp,
            nonceStr: payData.nonceStr,
            package: payData.package,
            signType: payData.signType || 'RSA',
            paySign: payData.paySign,
            success: () => {
              this.setData({ paying: false });
              wx.showToast({ title: '支付成功', icon: 'success' });
              // 主动查询支付状态，确保订单更新
              this.confirmPayment(orderNo);
            },
            fail: (err) => {
              this.setData({ paying: false });
              if (err && err.errMsg && err.errMsg.includes('cancel')) {
                wx.showToast({ title: '已取消支付', icon: 'none' });
              } else {
                wx.showToast({ title: '支付失败，可重试', icon: 'none' });
              }
            }
          });
        } else {
          this.setData({ paying: false });
          wx.showToast({ title: res.data.msg || '支付配置错误', icon: 'none' });
        }
      },
      fail: () => {
        this.setData({ paying: false });
        wx.hideLoading();
        wx.showToast({ title: '网络错误', icon: 'none' });
      }
    });
  },

  // 支付成功后查询支付状态，确保订单更新
  confirmPayment(orderNo) {
    wx.request({
      url: `${app.globalData.apiBase}/api/pay.php?action=query&order_no=${orderNo}`,
      success: (res) => {
        // query 接口会自动更新订单状态
setTimeout(() => {
          this.resetAfterPay();
          // 跳转到订单详情页
          wx.navigateTo({
            url: `/pages/order-detail/order-detail?order_no=${orderNo}`
          });
        }, 800);
      },
      fail: () => {
        setTimeout(() => {
          this.resetAfterPay();
          wx.navigateTo({
            url: `/pages/order-detail/order-detail?order_no=${orderNo}`
          });
        }, 800);
      }
    });
  },

  // 支付栏的「微信支付」按钮（取消支付后重试）
  wxPay() {
    if (this.data.paying) return;
    if (!this.data.orderNo) return;
    const userInfo = wx.getStorageSync('userInfo');
    const openid = (userInfo && userInfo.openid) || '';
    this.setData({ paying: true });
    wx.showLoading({ title: '唤起微信支付...' });
    this.requestPrepay(this.data.orderNo, openid);
  },

  // 取消订单
  cancelPay() {
    if (!this.data.orderNo) {
      this.resetAfterPay();
      return;
    }
    wx.showModal({
      title: '提示',
      content: '确定取消这个订单吗？',
      success: (res) => {
        if (res.confirm) {
          wx.request({
            url: `${app.globalData.apiBase}/api/order.php?action=cancel`,
            method: 'POST',
            data: { order_no: this.data.orderNo },
            success: () => {
              wx.showToast({ title: '订单已取消', icon: 'success' });
              this.resetAfterPay();
            }
          });
        }
      }
    });
  },

  // 支付/取消后重置
resetAfterPay() {
    this.setData({
      showPay: false,
      orderId: null,
      orderNo: null,
      payAmount: '0.00',
      cartList: [],
      totalPrice: '0.00',
      finalPrice: '0.00',
      remark: '',
      paying: false
    });
    app.globalData.cartList = [];
    app.saveCart();
  },

  onShareAppMessage() {
    const cfg = app.globalData._share_cart || {};
    return {
      title: cfg.share_title || '我的购物车 - 桃之',
      path: '/pages/cart/cart',
      imageUrl: cfg.share_img || ''
    };
  },

  onShareTimeline() {
    const cfg = app.globalData._share_cart || {};
    return {
      title: cfg.share_title || '我的购物车 - 桃之',
      imageUrl: cfg.share_img || '',
      query: ''
    };
  }
})
