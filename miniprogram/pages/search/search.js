const app = getApp();

Page({
  data: {
    keyword: '',
    historyList: [],
    hotList: ['安全套', '情趣内衣', '润滑液', '飞机杯', '延时喷剂', '跳蛋', '按摩棒'],
    results: [],
    loading: false
  },
  
  onLoad() {
    this.loadHistory();
  },
  
  loadHistory() {
    const history = wx.getStorageSync('searchHistory') || [];
    this.setData({ historyList: history });
  },
  
  onInput(e) {
    this.setData({ keyword: e.detail.value });
    if (!e.detail.value) {
      this.setData({ results: [] });
    }
  },
  
  onSearch() {
    const keyword = this.data.keyword.trim();
    if (!keyword) return;
    this.saveHistory(keyword);
    this.doSearch(keyword);
  },
  
  saveHistory(keyword) {
    let history = this.data.historyList;
    history = history.filter(h => h !== keyword);
    history.unshift(keyword);
    history = history.slice(0, 10);
    wx.setStorageSync('searchHistory', history);
    this.setData({ historyList: history });
  },
  
  clearHistory() {
    wx.removeStorageSync('searchHistory');
    this.setData({ historyList: [] });
  },
  
  tapHistory(e) {
    const word = e.currentTarget.dataset.word;
    this.setData({ keyword: word });
    this.doSearch(word);
  },
  
  doSearch(keyword) {
    this.setData({ loading: true });
    wx.request({
      url: `${app.globalData.apiBase}/api/product.php`,
      data: { keyword },
      success: (res) => {
        if (res.data.code === 0) {
          this.setData({ results: res.data.data || [] });
        } else {
          this.setData({ results: [] });
        }
      },
      fail: () => {
        this.setData({ results: [] });
      },
      complete: () => {
        this.setData({ loading: false });
      }
    });
  },
  
  goProduct(e) {
    const id = e.currentTarget.dataset.id;
    wx.navigateTo({ url: `/pages/product/product?id=${id}` });
  },
  
  onCancel() {
    wx.navigateBack();
  }
})
