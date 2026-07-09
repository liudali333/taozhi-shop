const app = getApp();

Page({
  data: {
    addressList: [],
    showModal: false,
    editingIndex: -1,
    form: {
      name: '',
      phone: '',
      region: '',
      regionArr: [],
      detail: '',
      lat: 0,
      lng: 0,
      addressName: '',
      isDefault: false
    }
  },

  onShow() {
    this.loadAddresses();
  },

  // 加载地址列表（先从本地，再从后端同步）
  loadAddresses() {
    const localList = wx.getStorageSync('addressList') || [];
    this.setData({ addressList: localList });

    // 从后端同步
    const userInfo = wx.getStorageSync('userInfo');
    if (userInfo && userInfo.id) {
      wx.request({
        url: `${app.globalData.apiBase}/api/address.php?action=list`,
        data: { user_id: userInfo.id },
        success: (res) => {
          if (res.data.code === 0 && res.data.data) {
            this.setData({ addressList: res.data.data });
            wx.setStorageSync('addressList', res.data.data);
          }
        }
      });
    }
  },

  // 新增地址
  addAddress() {
    this.setData({
      showModal: true,
      editingIndex: -1,
      form: {
        name: '',
        phone: '',
        region: '',
        regionArr: [],
        detail: '',
        lat: 0,
        lng: 0,
        addressName: '',
        isDefault: this.data.addressList.length === 0
      }
    });
  },

  // 编辑地址
  editAddress(e) {
    const item = e.currentTarget.dataset.item;
    const idx = this.data.addressList.findIndex(a => a.id === item.id);
    this.setData({
      showModal: true,
      editingIndex: idx,
      form: {
        name: item.name || '',
        phone: item.phone || '',
        region: item.region || '',
        regionArr: item.region ? item.region.split(' ') : [],
        detail: item.detail || '',
        lat: item.lat || 0,
        lng: item.lng || 0,
        addressName: item.addressName || '',
        isDefault: item.isDefault || false
      }
    });
  },

  // 删除地址
  deleteAddress(e) {
    const id = e.currentTarget.dataset.id;
    wx.showModal({
      title: '提示',
      content: '确定删除该地址？',
      success: (res) => {
        if (res.confirm) {
          let list = this.data.addressList.filter(a => a.id !== id);
          this.setData({ addressList: list });
          wx.setStorageSync('addressList', list);
          this.syncToServer(list);
        }
      }
    });
  },

  // 切换默认
  toggleDefault(e) {
    const id = e.currentTarget.dataset.id;
    const isDefault = e.currentTarget.dataset.default === true || e.currentTarget.dataset.default === 'true';
    let list = this.data.addressList.map(a => {
      if (isDefault && a.id === id) {
        return { ...a, isDefault: false };
      }
      if (!isDefault && a.id === id) {
        return { ...a, isDefault: true };
      }
      if (!isDefault) {
        return { ...a, isDefault: false };
      }
      return a;
    });
    this.setData({ addressList: list });
    wx.setStorageSync('addressList', list);
    this.syncToServer(list);
  },

  // 选择地址（返回上一页时携带数据）
  selectAddress(e) {
    const item = e.currentTarget.dataset.item;
    const pages = getCurrentPages();
    const prevPage = pages[pages.length - 2];
    if (prevPage && prevPage.route === 'pages/cart/cart') {
      prevPage.setData({ selectedAddress: item });
      prevPage.onAddressSelected(item);
    }
    wx.navigateBack();
  },

  // 表单输入
  onFormInput(e) {
    const field = e.currentTarget.dataset.field;
    const val = e.detail.value;
    this.setData({
      [`form.${field}`]: val
    });
  },

  // 地区选择
  onRegionChange(e) {
    const val = e.detail.value;
    this.setData({
      'form.region': val.join(' '),
      'form.regionArr': val
    });
  },

  // 默认开关
  onDefaultChange(e) {
    this.setData({ 'form.isDefault': e.detail.value });
  },

  // 自动地理编码 + 打开地图微调
  onLocateAddress() {
    const form = this.data.form;
    const region = form.region || '';
    const detail = (form.detail || '').trim();

    if (!region) { wx.showToast({ title: '请先选择所在地区', icon: 'none' }); return; }
    if (!detail) { wx.showToast({ title: '请先填写详细地址', icon: 'none' }); return; }

    const fullAddress = region.replace(/ /g, '') + detail;
    const city = region.split(' ')[1] || '';

    wx.showLoading({ title: '定位中...' });

    wx.request({
      url: `${app.globalData.apiBase}/geocode.php`,
      method: 'GET',
      data: {
        action: 'geocode',
        address: fullAddress,
        city: city
      },
      success: (res) => {
        wx.hideLoading();
        if (res.data.code === 0 && res.data.data) {
          const d = res.data.data;
          const lat = parseFloat(d.lat);
          const lng = parseFloat(d.lng);
          if (!isNaN(lat) && !isNaN(lng) && lat > 0) {
            // 打开地图页微调
            wx.navigateTo({
              url: `/pages/map-picker/map-picker?lat=${lat}&lng=${lng}&address=${encodeURIComponent(d.address || fullAddress)}`
            });
            return;
          }
        }
        wx.showToast({ title: '地址解析失败，请手动选择', icon: 'none' });
        // 降级：直接打开地图（无初始坐标）
        wx.navigateTo({ url: '/pages/map-picker/map-picker' });
      },
      fail: () => {
        wx.hideLoading();
        wx.showToast({ title: '网络错误，请重试', icon: 'none' });
      }
    });
  },

  // 地图选择页回调（接收最终坐标）
  setLocation(data) {
    if (data && data.latitude && data.longitude) {
      this.setData({
        'form.lat': data.latitude,
        'form.lng': data.longitude,
        'form.addressName': data.address || ''
      });
    }
  },

  // 保存地址
  saveAddress() {
    const form = this.data.form;
    if (!form.name.trim()) { wx.showToast({ title: '请填写收货人', icon: 'none' }); return; }
    if (!/^1\d{10}$/.test(form.phone)) { wx.showToast({ title: '请填写正确手机号', icon: 'none' }); return; }
    if (!form.region) { wx.showToast({ title: '请选择所在地区', icon: 'none' }); return; }
    if (!form.detail.trim()) { wx.showToast({ title: '请填写详细地址', icon: 'none' }); return; }

    let list = [...this.data.addressList];
    const id = Date.now().toString(36) + Math.random().toString(36).slice(2, 6);

    const address = {
      id: this.data.editingIndex === -1 ? id : list[this.data.editingIndex].id,
      name: form.name.trim(),
      phone: form.phone.trim(),
      region: form.region,
      detail: form.detail.trim(),
      lat: form.lat,
      lng: form.lng,
      addressName: form.addressName,
      isDefault: form.isDefault
    };

    if (this.data.editingIndex === -1) {
      // 新增
      if (address.isDefault) {
        list.forEach(a => a.isDefault = false);
      }
      list.push(address);
    } else {
      // 编辑
      if (address.isDefault) {
        list.forEach((a, i) => {
          if (i !== this.data.editingIndex) a.isDefault = false;
        });
      }
      list[this.data.editingIndex] = address;
    }

    this.setData({ addressList: list, showModal: false });
    wx.setStorageSync('addressList', list);
    this.syncToServer(list);

    wx.showToast({ title: '保存成功', icon: 'success' });
  },

  // 关闭弹窗
  closeModal() {
    this.setData({ showModal: false });
  },

  // 同步到服务器
  syncToServer(list) {
    const userInfo = wx.getStorageSync('userInfo');
    if (!userInfo || !userInfo.id) return;
    wx.request({
      url: `${app.globalData.apiBase}/api/address.php?action=sync`,
      method: 'POST',
      data: { user_id: userInfo.id, addresses: JSON.stringify(list) },
      fail: () => {} // 静默失败，下次同步
    });
  }
})
