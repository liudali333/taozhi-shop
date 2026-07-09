const app = getApp();

Page({
  data: {
    storeName: '桃之成人用品',
    storeNotice: '',
    isOpen: true,
    categories: [],        // 全部（含 L1+L2）
    categoriesL1: [],      // 仅一级分类
    categoriesL2Map: {},   // L1 ID -> [L2 categories]
    categoriesHasL2: {},   // L1 ID -> bool (是否有二级子分类，预计算避免 WXML 编译器解析复杂表达式)
    currentCategoryId: 0,
    currentCategoryL1: '',  // 当前选中的 L1 名称
    currentCategoryL2: '',  // 当前选中的 L2 名称
    products: [],
    banners: [],
    hasBanners: false,       // 预计算 banners.length > 0（避免 WXML .length 编译器 BUG）
    hasProducts: false,      // 预计算 products.length > 0
    isProductsEmpty: true,   // 预计算 products.length === 0
    cartCount: 0,
    cartTotal: '0.00',
    loading: false,
    loadingMore: false,
    page: 1,
    pageSize: 30,
    hasMore: true,
    expandedCategoryId: null,  // 当前展开显示二级分类的 L1 ID
    sidebarCollapsed: false    // 侧边栏是否折叠
  },

  onLoad() {
    app.enablePageShare('home');
    this.loadStoreInfo();
    this.loadCategories();
    this.loadBanners();
    // 不在首页加载商品，等分类加载完成后默认选第一个
  },

  onShow() {
    this.updateCart();
  },

  // 发送给朋友（分享给好友或群聊）
  onShareAppMessage() {
    const cfg = app.globalData._share_home || {};
    return {
      title: cfg.share_title || '桃之 — 私密情趣，用心甄选',
      path: '/pages/home/home',
      imageUrl: cfg.share_img || ''
    };
  },

  // 分享到朋友圈
  onShareTimeline() {
    const cfg = app.globalData._share_home || {};
    return {
      title: cfg.share_title || '桃之 — 私密情趣，用心甄选',
      imageUrl: cfg.share_img || '',
      query: ''
    };
  },

  // 加载门店信息
  loadStoreInfo() {
    wx.request({
      url: `${app.globalData.apiBase}/api/store.php`,
      success: (res) => {
        if (res.data.code === 0) {
          const store = res.data.data;
          this.setData({
            storeName: store.name,
            storeNotice: store.notice || '',
            isOpen: store.is_open === true
          });
          app.globalData.store = store;
        }
      }
    });
  },

  // 更新购物车
  updateCart() {
    this.setData({
      cartCount: app.getCartCount(),
      cartTotal: app.getCartTotal().toFixed(2)
    });
  },

  // 加载分类（一二级树）
  loadCategories() {
    wx.request({
      url: `${app.globalData.apiBase}/api/category.php`,
      success: (res) => {
        if (res.data.code === 0) {
          const all = res.data.data || [];
          const l1 = all.filter(c => c.level == 1);
          l1.sort((a, b) => (a.sort || a.id) - (b.sort || b.id));

          // 构建 L2 map：确保每个 l1 都有空数组，避免 undefined
          const l2Map = {};
          l1.forEach(cat => {
            l2Map[cat.id] = all.filter(c => c.level == 2 && c.parent_id == cat.id);
          });

          // 预计算每个一级分类是否有二级子分类
          const hasL2Map = {};
          Object.keys(l2Map).forEach(id => {
            hasL2Map[id] = (l2Map[id] || []).length > 0;
          });

          this.setData({
            categories: all,
            categoriesL1: l1,
            categoriesL2Map: l2Map,
            categoriesHasL2: hasL2Map
          });

          // 默认选中第一个一级分类
          if (l1.length > 0) {
            this.selectCategoryById(l1[0].id, l1[0].name);
          } else {
            this.loadProducts(true);
          }
        }
      },
      fail: () => {
        // 接口失败时兜底：空分类
        this.setData({ categories: [], categoriesL1: [], categoriesL2Map: {}, categoriesHasL2: {} });
      }
    });
  },

  // 根据 ID 选择分类（用于默认选中）
  selectCategoryById(id, name) {
    this.setData({
      currentCategoryId: id,
      currentCategoryL1: name,
      currentCategoryL2: '',
      expandedCategoryId: id  // 展开显示二级分类
    });
    this.loadProducts(true);
  },

  // 加载轮播图
  loadBanners() {
    wx.request({
      url: `${app.globalData.apiBase}/api/banner.php`,
      success: (res) => {
        if (res.data.code === 0) {
          const banners = res.data.data || [];
          this.setData({ banners, hasBanners: banners.length > 0 });
        }
      }
    });
  },

  // 加载商品
  loadProducts(refresh = false) {
    if (refresh) {
      this.setData({ loading: true, page: 1, hasMore: true });
    } else {
      this.setData({ loadingMore: true });
    }

    const { currentCategoryId, currentCategoryL1, currentCategoryL2, page, pageSize } = this.data;

    let url = `${app.globalData.apiBase}/api/product.php`;
    let data = { page, pageSize };

    if (currentCategoryId > 0) {
      data.category_id = currentCategoryId;
    } else if (currentCategoryL1) {
      data.category_l1 = currentCategoryL1;
      if (currentCategoryL2) {
        data.category_l2 = currentCategoryL2;
      }
    }

    wx.request({
      url,
      data,
      success: (res) => {
        if (res.data.code === 0) {
          let newProducts = res.data.data || [];
          // 计算折扣金额（WXML不支持toFixed）
          newProducts = newProducts.map(p => ({
            ...p,
            discountAmount: p.sale_price && p.sale_price < p.price
              ? Math.round(p.price - p.sale_price)
              : 0
          }));
          const allProducts = refresh ? newProducts : this.data.products.concat(newProducts);
          this.setData({
            products: allProducts,
            hasMore: newProducts.length >= pageSize,
            hasProducts: allProducts.length > 0,
            isProductsEmpty: allProducts.length === 0,
            page: page + 1
          });
        }
      },
      complete: () => {
        this.setData({ loading: false, loadingMore: false });
      }
    });
  },

  // 选择分类（左侧栏点击）
  selectCategory(e) {
    const id = e.currentTarget.dataset.id;
    const name = e.currentTarget.dataset.name;
    const hasL2 = e.currentTarget.dataset.hasL2;

    if (hasL2) {
      // 有二级分类，切换展开/折叠
      const expanded = this.data.expandedCategoryId == id;
      this.setData({
        expandedCategoryId: expanded ? null : id
      });
    } else {
      // 没有二级分类，直接选中
      this.setData({
        currentCategoryId: id,
        currentCategoryL1: name,
        currentCategoryL2: '',
        expandedCategoryId: null
      });
      this.loadProducts(true);
    }
  },

  // 选择二级分类
  selectL2Category(e) {
    const id = e.currentTarget.dataset.id;
    const l1Id = e.currentTarget.dataset.l1Id;
    const l2Name = e.currentTarget.dataset.l2Name;

    // 查找 L1 名称
    const l1Name = this.data.categoriesL1.find(c => c.id == l1Id)?.name || '';

    this.setData({
      currentCategoryId: id,
      currentCategoryL1: l1Name,
      currentCategoryL2: l2Name
    });
    this.loadProducts(true);
  },

  // 切换侧边栏折叠
  toggleSidebar() {
    this.setData({
      sidebarCollapsed: !this.data.sidebarCollapsed
    });
  },

  // 加载更多
  loadMore() {
    if (!this.data.loadingMore && this.data.hasMore) {
      this.loadProducts(false);
    }
  },

  // 搜索页面
  goToSearch() {
    wx.navigateTo({ url: '/pages/search/search' });
  },

  // 商品详情
  goToProduct(e) {
    const id = e.currentTarget.dataset.id;
    wx.navigateTo({ url: `/pages/product/product?id=${id}` });
  },

  // 添加到购物车
  addToCart(e) {
    const product = e.currentTarget.dataset.product;
    if (product.stock <= 0) {
      wx.showToast({ title: '商品已售罄', icon: 'none' });
      return;
    }
    app.addToCart(product);
    this.updateCart();
    wx.showToast({ title: '已加入购物车', icon: 'success', duration: 1500 });
  },

  // 购物车
  goToCart() {
    wx.navigateTo({ url: '/pages/cart/cart' });
  },

  // 轮播图点击
  onBannerTap(e) {
    const url = e.currentTarget.dataset.url;
    if (url) {
      wx.navigateTo({ url });
    }
  }
})
