const app = getApp();

Page({
  data: {
    hasLogin: false,
    userInfo: null,
    couponCount: 0,
    showNicknameModal: false,
    inputNickname: '',
    tempAvatar: ''
  },
  
  onLoad() { app.enablePageShare('mine'); this.checkLogin(); },

  onShow() {
    this.checkLogin();
    if (this.data.hasLogin) this.loadUserData();
  },
  
  checkLogin() {
    let userInfo = wx.getStorageSync('userInfo');
    if (userInfo && userInfo.avatar) {
      userInfo.avatar = this.normalizeAvatar(userInfo.avatar);
    }
    this.setData({
      hasLogin: !!userInfo,
      userInfo: userInfo || null
    });
  },

  // 将后端返回的相对路径（/uploads/...）补全为绝对 URL，否则小程序 image 会当成包内路径加载失败
  normalizeAvatar(url) {
    if (!url) return '';
    if (url.indexOf('http://') === 0 || url.indexOf('https://') === 0) return url;
    if (url.indexOf('/') === 0) return app.globalData.apiBase + url;
    return url;
  },
  
  loadUserData() {
    const userInfo = wx.getStorageSync('userInfo');
    if (!userInfo || !userInfo.id) return;
    
    wx.request({ url: `${app.globalData.apiBase}/api/coupon.php?action=count`, data: { user_id: userInfo.id },
      success: (r) => { if (r.data.code === 0) this.setData({ couponCount: r.data.data }); } });
    wx.request({ url: `${app.globalData.apiBase}/api/address.php?action=list`, data: { user_id: userInfo.id },
      success: (r) => { if (r.data.code === 0 && r.data.data && r.data.data.length > 0) { wx.setStorageSync('addressList', r.data.data); } } });
  },
  
  goLogin() {
    const apiBase = app.globalData.apiBase;
    wx.showLoading({ title: '登录中...' });

    // 1. 获取微信登录 code
    wx.login({
      success: (res) => {
        const code = res.code || '';
        const localId = this.hashStr(code);
        wx.setStorageSync('localId', localId);

        // 2. 请求后端注册/登录
        const url = apiBase + '/api/user.php?action=login';
        console.log('[登录] 请求地址:', url);
        console.log('[登录] 发送数据:', { code: code.substring(0,10)+'...', local_id: localId });

        wx.request({
          url: url,
          method: 'POST',
          data: { code: code, local_id: localId },
          success: (r) => {
            wx.hideLoading();
            console.log('[登录] 后端响应:', JSON.stringify(r.data));

            if (r.data && r.data.code === 0) {
              // ✅ 后端注册成功
              const u = r.data.data;
              const userInfo = { id: u.id, openid: u.openid || '', nickname: u.nickname || '微信用户', avatar: this.normalizeAvatar(u.avatar || ''), phone: u.phone || '' };
              wx.setStorageSync('userInfo', userInfo);
              app.globalData.userInfo = userInfo;  // 同步到全局，确保支付时能读到 openid
              this.setData({ hasLogin: true, userInfo: userInfo });
              this.loadUserData();
              wx.showToast({ title: '登录成功', icon: 'success' });
              console.log('[登录] 成功，用户ID:', u.id);

              // 新用户（无头像）提示完善资料
              if (!userInfo.avatar) {
                this.setData({ showNicknameModal: true, inputNickname: '', tempAvatar: '' });
              }
            } else {
              // ❌ 后端返回错误
              const errMsg = r.data && r.data.msg ? r.data.msg : '未知错误';
              console.error('[登录] 后端错误:', errMsg, JSON.stringify(r.data));
              wx.showModal({
                title: '登录失败',
                content: '后端返回: ' + errMsg + '\n（请检查 PHP 文件是否已上传到服务器）',
                showCancel: false
              });
              // 兜底：本地登录
              this.localLogin(code, localId);
            }
          },
          fail: (err) => {
            wx.hideLoading();
            // ❌ 网络不通
            console.error('[登录] 网络请求失败:', JSON.stringify(err));
            wx.showModal({
              title: '网络错误',
              content: '无法连接到 ' + apiBase + '\n请确认：\n1. 服务器是否正常运行\n2. PHP 文件是否已上传\n3. 微信开发者工具中「不校验合法域名」是否勾选',
              showCancel: false
            });
            this.localLogin(code, localId);
          }
        });
      },
      fail: () => {
        wx.hideLoading();
        wx.showToast({ title: '微信登录失败', icon: 'none' });
      }
    });
  },

  localLogin(code, localId) {
    const id = parseInt(localId.slice(0, 8), 36) % 100000 + 10000;
    const userInfo = { id, nickname: '桃之用户', avatar: '', phone: '' };
    wx.setStorageSync('userInfo', userInfo);
    app.globalData.userInfo = userInfo;  // 同步到全局
    this.setData({ hasLogin: true, userInfo: userInfo });
    this.loadUserData();

    if (!wx.getStorageSync('nicknameSetup')) {
      this.setData({ showNicknameModal: true, inputNickname: '', tempAvatar: '' });
      wx.setStorageSync('nicknameSetup', true);
    }
    wx.showToast({ title: '已本地登录', icon: 'success' });
  },

  hashStr(str) {
    let h = 0;
    for (let i = 0; i < str.length; i++) { h = ((h << 5) - h) + str.charCodeAt(i); h |= 0; }
    return Math.abs(h).toString(36);
  },

  // ========== 头像昵称填写能力 ==========
  onChooseAvatar(e) {
    const avatarUrl = e.detail.avatarUrl;
    if (!avatarUrl) return;
    this.setData({ tempAvatar: avatarUrl });
    // 弹窗内选择：先暂存，保存时再上传；首页直接选择：立即上传
    if (!this.data.showNicknameModal) {
      this.uploadAvatar(avatarUrl);
    }
  },

  uploadAvatar(tempPath) {
    const userInfo = wx.getStorageSync('userInfo');
    if (!userInfo || !userInfo.id) return;
    wx.uploadFile({
      url: app.globalData.apiBase + '/api/user.php?action=update_avatar',
      filePath: tempPath,
      name: 'avatar',
      formData: { user_id: userInfo.id },
      success: (r) => {
        try {
          const res = JSON.parse(r.data);
          if (res.code === 0 && res.data && res.data.avatar) {
            const newInfo = Object.assign({}, wx.getStorageSync('userInfo'), { avatar: this.normalizeAvatar(res.data.avatar) });
            wx.setStorageSync('userInfo', newInfo);
            app.globalData.userInfo = newInfo;
            this.setData({ userInfo: newInfo });
            wx.showToast({ title: '头像已更新', icon: 'success' });
          }
        } catch (e) { console.error('解析头像上传响应失败', e); }
      },
      fail: (err) => { console.error('头像上传失败', err); wx.showToast({ title: '头像上传失败', icon: 'none' }); }
    });
  },

  onNicknameInput(e) { this.setData({ inputNickname: e.detail.value }); },
  onNicknameBlur(e) {
    const nickname = (e.detail.value || '').trim();
    if (!nickname) return;
    const userInfo = wx.getStorageSync('userInfo');
    if (!userInfo || !userInfo.id) return;
    // 昵称变化才保存
    if (nickname === userInfo.nickname) return;
    this.saveField('nickname', nickname);
  },

  saveField(field, value) {
    const userInfo = wx.getStorageSync('userInfo');
    if (!userInfo || !userInfo.id) return;
    wx.request({
      url: app.globalData.apiBase + '/api/user.php?action=update',
      method: 'POST',
      data: { user_id: userInfo.id, field: field, value: value },
      success: (r) => {
        if (r.data && r.data.code === 0) {
          const newInfo = Object.assign({}, wx.getStorageSync('userInfo'), { [field]: value });
          wx.setStorageSync('userInfo', newInfo);
          app.globalData.userInfo = newInfo;
          this.setData({ userInfo: newInfo });
        }
      }
    });
  },

  saveProfile() {
    const nickname = (this.data.inputNickname || '').trim() || (this.data.userInfo.nickname || '桃之用户');
    const userInfo = wx.getStorageSync('userInfo');
    // 保存昵称
    if (nickname && nickname !== userInfo.nickname) {
      this.saveField('nickname', nickname);
    }
    // 保存头像（如有选择）
    if (this.data.tempAvatar) {
      this.uploadAvatar(this.data.tempAvatar);
    }
    this.setData({ showNicknameModal: false, tempAvatar: '' });
    wx.showToast({ title: '资料已保存', icon: 'success' });
  },
  closeNickname() { this.setData({ showNicknameModal: false, tempAvatar: '' }); },
  goOrder(e) {
    if (!this.data.hasLogin) return this.goLogin();
    const type = e && e.currentTarget && e.currentTarget.dataset ? e.currentTarget.dataset.type : 'all';
    wx.navigateTo({ url: '/pages/order/order?type=' + type });
  },
  goCoupons() { if (!this.data.hasLogin) return this.goLogin(); wx.navigateTo({ url: '/pages/coupon/coupon' }); },
  goAddress() { wx.navigateTo({ url: '/pages/address/address' }); },
  goDelivery() { wx.navigateTo({ url: '/pages/delivery/delivery' }); },
  goHelp() { wx.navigateTo({ url: '/pages/help/help' }); },
  goAbout() { wx.navigateTo({ url: '/pages/about/about' }); },
  callService() { wx.makePhoneCall({ phoneNumber: '400-888-8888' }); },

  onShareAppMessage() {
    const cfg = app.globalData._share_mine || {};
    return {
      title: cfg.share_title || '我的 - 桃之',
      path: '/pages/my/my',
      imageUrl: cfg.share_img || ''
    };
  },

  onShareTimeline() {
    const cfg = app.globalData._share_mine || {};
    return {
      title: cfg.share_title || '我的 - 桃之',
      imageUrl: cfg.share_img || '',
      query: ''
    };
  }
})
