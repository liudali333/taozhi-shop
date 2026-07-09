Page({
  data: {
    latitude: 39.908823,
    longitude: 116.397470,
    scale: 16,
    markers: [],
    addressName: '',
    isDragging: false
  },

  onLoad(options) {
    // 接收参数：lat, lng, address
    if (options.lat && options.lng) {
      var lat = parseFloat(options.lat);
      var lng = parseFloat(options.lng);
      this.setData({
        latitude: lat,
        longitude: lng,
        markers: [{
          id: 1,
          latitude: lat,
          longitude: lng,
          iconPath: '/images/location.png',
          width: 32,
          height: 32
        }]
      });
      
      if (options.address) {
        this.setData({ addressName: decodeURIComponent(options.address) });
      } else {
        // 逆地理编码获取地址
        this.reverseGeocode(lat, lng);
      }
    } else {
      // 无参数，获取当前位置
      this.getUserLocation();
    }
  },

  getUserLocation() {
    var that = this;
    wx.getLocation({
      type: 'gcj02',
      success(res) {
        that.setData({
          latitude: res.latitude,
          longitude: res.longitude,
          markers: [{
            id: 1,
            latitude: res.latitude,
            longitude: res.longitude,
            iconPath: '/images/location.png',
            width: 32,
            height: 32
          }]
        });
        that.reverseGeocode(res.latitude, res.longitude);
      },
      fail() {
        wx.showToast({ title: '定位失败，请手动选择', icon: 'none' });
      }
    });
  },

  // 地图区域变化（拖动地图）
  onRegionChange(e) {
    if (e.type === 'end') {
      // 获取地图中心点
      var that = this;
      var mapCtx = wx.createMapContext('pickerMap');
      mapCtx.getCenterLocation({
        success(res) {
          that.setData({
            latitude: res.latitude,
            longitude: res.longitude
          });
          that.reverseGeocode(res.latitude, res.longitude);
        }
      });
    }
  },

  // 逆地理编码（调用后端接口）
  reverseGeocode(lat, lng) {
    var that = this;
    wx.request({
      url: getApp().globalData.apiBase + '/geocode.php',
      method: 'GET',
      data: {
        action: 'reverse',
        lat: lat,
        lng: lng
      },
      success(res) {
        if (res.data.code === 0 && res.data.data.address) {
          that.setData({ addressName: res.data.data.address });
        }
      }
    });
  },

  // 取消
  onCancel() {
    wx.navigateBack();
  },

  // 确定选择
  onConfirm() {
    var pages = getCurrentPages();
    var prevPage = pages[pages.length - 2];
    
    // 返回坐标和地址给上一页
    if (prevPage && prevPage.setLocation) {
      prevPage.setLocation({
        latitude: this.data.latitude,
        longitude: this.data.longitude,
        address: this.data.addressName
      });
    }
    
    wx.navigateBack();
  }
});
