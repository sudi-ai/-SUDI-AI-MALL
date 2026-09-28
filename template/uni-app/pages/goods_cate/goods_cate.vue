<template>
  <view class="nu-catalog" :style="colorStyle">
    <view class="nu-catalog-content">
      <view class="nu-catalog-brand"><BrandLockup compact /><text class="nu-catalog-label">THE COLLECTION</text></view>
      <view class="nu-catalog-heading"><text>款式目录</text><text class="nu-catalog-caption">日常之间，自有风格</text></view>
      <view class="nu-catalog-search">
        <text class="iconfont icon-sousuo"></text>
        <input v-model="keyword" placeholder="搜索女装款式" confirm-type="search" @confirm="search" />
        <button v-if="keyword.trim()" class="nu-search-submit" @click="search">搜索</button>
      </view>
      <view v-if="loading && !categories.length" class="nu-catalog-state">正在加载分类…</view>
      <view v-else-if="error" class="nu-catalog-state"><text>分类暂时未能加载</text><button @click="loadCategories">重新加载</button></view>
      <view v-else-if="!categories.length" class="nu-catalog-state">款式正在整理中，稍后再来看看</view>
      <template v-else>
        <scroll-view v-if="categories.length > 1" scroll-x class="nu-catalog-tabs">
          <button v-for="category in categories" :key="category.id" :class="{active: category.id === activeId}" @click="selectCategory(category.id)">{{ category.cate_name }}</button>
        </scroll-view>
        <view class="nu-catalog-all" @click="browse(activeCategory)">
          <view><text class="nu-catalog-label">EXPLORE {{ activeCategory.cate_name }}</text><view class="nu-all-title">全部商品</view></view>
          <text class="nu-arrow">↗</text>
        </view>
        <view v-if="children.length" class="nu-catalog-section">
          <view class="nu-section-title"><text>按款式选购</text><text>CATEGORIES</text></view>
          <view class="nu-category-grid">
            <view v-for="(category, index) in primaryCategories" :key="category.id" class="nu-category-link" @click="browse(category, true)">
              <text class="nu-category-number">{{ index + 1 < 10 ? '0' + (index + 1) : index + 1 }}</text>
              <text class="nu-category-name">{{ category.cate_name }}</text><text class="nu-category-arrow">↗</text>
            </view>
          </view>
          <button v-if="otherCategories.length" class="nu-more-toggle" :aria-expanded="expanded" @click="expanded = !expanded">
            <text>{{ expanded ? '收起更多款式' : '更多款式' }} · {{ otherCategories.length }}</text><text>{{ expanded ? '−' : '+' }}</text>
          </button>
          <view v-if="expanded" class="nu-category-grid nu-secondary-grid">
            <view v-for="category in otherCategories" :key="category.id" class="nu-category-link" @click="browse(category, true)"><text class="nu-category-name">{{ category.cate_name }}</text><text class="nu-category-arrow">↗</text></view>
          </view>
        </view>
      </template>
      <view class="nu-catalog-signature">NUYOAHCC · HANGZHOU</view>
    </view>
    <pageFooter />
  </view>
</template>
<script>
import colors from '@/mixins/color';
import { getCategoryList } from '@/api/store.js';
import pageFooter from '@/components/pageFooter/index.vue';
export default {
  components: { pageFooter },
  mixins: [colors],
  data() { return { categories: [], activeId: null, keyword: '', loading: false, error: false, expanded: false }; },
  computed: {
    activeCategory() { return this.categories.find(item => item.id === this.activeId) || this.categories[0] || {}; },
    children() { return this.activeCategory.children || []; },
    primaryCategories() { return this.children.slice(0, 8); },
    otherCategories() { return this.children.slice(8); }
  },
  onShow() { this.loadCategories(); },
  methods: {
    async loadCategories() {
      if (this.loading) return;
      this.loading = true;
      this.error = false;
      try {
        const res = await getCategoryList();
        this.categories = Array.isArray(res.data) ? res.data : [];
        if (!this.categories.some(item => item.id === this.activeId)) this.activeId = this.categories.length ? this.categories[0].id : null;
      } catch (e) { this.error = true; }
      finally { this.loading = false; }
    },
    selectCategory(id) { this.activeId = id; this.expanded = false; },
    browse(category, child = false) {
      if (!category || !category.id) return;
      uni.navigateTo({ url: '/pages/goods/goods_list/index?' + (child ? 'sid=' : 'cid=') + category.id + '&title=' + encodeURIComponent(category.cate_name) });
    },
    search() {
      const keyword = this.keyword.trim();
      if (keyword) uni.navigateTo({ url: '/pages/goods/goods_list/index?searchValue=' + encodeURIComponent(keyword) });
    }
  }
};
</script>
<style scoped lang="scss">
.nu-catalog { min-height:100vh;background:#f8f6f2;color:#1c1d1a; }
.nu-catalog-content { padding:40rpx 36rpx 140rpx; }
.nu-catalog-brand { display:flex;justify-content:space-between;align-items:flex-start;gap:12rpx; }
.nu-catalog-label { font-size:16rpx;letter-spacing:2rpx;color:#77776e;line-height:1.8; }
.nu-catalog-brand > .nu-catalog-label { padding-top:14rpx;white-space:nowrap; }
.nu-catalog-heading { margin-top:58rpx;display:flex;align-items:baseline;justify-content:space-between;gap:12rpx;font-size:40rpx;letter-spacing:2rpx; }
.nu-catalog-caption { font-size:20rpx;color:#77776e;letter-spacing:1rpx; }
.nu-catalog-search { display:flex;align-items:center;gap:16rpx;min-height:86rpx;border-bottom:1px solid #d6d3cc;margin:18rpx 0 34rpx; }
.nu-catalog-search .iconfont { font-size:34rpx; }
.nu-catalog-search input { flex:1;min-width:0;font-size:25rpx; }
.nu-catalog button { padding:0;margin:0;border:0;border-radius:0;background:transparent;color:inherit;font-size:24rpx; }
.nu-catalog button::after { border:0; }
.nu-search-submit { min-width:80rpx;line-height:80rpx; }
.nu-catalog-tabs { white-space:nowrap;margin-bottom:26rpx; }
.nu-catalog-tabs button { display:inline-block;padding:12rpx 22rpx;border-bottom:1px solid transparent; }
.nu-catalog-tabs button.active { border-color:#1c1d1a; }
.nu-catalog-all { display:flex;align-items:center;justify-content:space-between;padding:28rpx 0 32rpx;border-bottom:1px solid #1c1d1a; }
.nu-all-title { margin-top:10rpx;font-size:37rpx;letter-spacing:2rpx; }
.nu-arrow { font-family:Arial,sans-serif;font-size:48rpx;font-weight:300; }
.nu-catalog-section { margin-top:38rpx; }
.nu-section-title { display:flex;align-items:center;justify-content:space-between;font-size:22rpx;margin-bottom:12rpx; }
.nu-section-title > text:last-child { font-size:16rpx;letter-spacing:2rpx;color:#77776e; }
.nu-category-grid { display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);column-gap:36rpx; }
.nu-category-link { min-height:106rpx;display:flex;align-items:center;border-bottom:1px solid #d6d3cc;gap:16rpx; }
.nu-category-number { font-size:17rpx;color:#929188;font-family:Georgia,serif; }
.nu-category-name { flex:1;font-size:29rpx;line-height:1.5; }
.nu-category-arrow { font-size:25rpx;color:#929188; }
.nu-more-toggle { display:flex;align-items:center;justify-content:space-between;width:100%;min-height:98rpx;letter-spacing:1rpx; }
.nu-secondary-grid .nu-category-name { font-size:26rpx; }
.nu-catalog-signature { text-align:center;margin-top:58rpx;font-size:16rpx;letter-spacing:3rpx;color:#929188; }
.nu-catalog-state { padding:70rpx 0;font-size:25rpx;color:#77776e;text-align:center; }
.nu-catalog-state button { margin-top:20rpx;text-decoration:underline; }
</style>
