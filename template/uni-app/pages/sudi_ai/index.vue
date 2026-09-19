<template>
  <view class="sudi-ai-page">
    <view class="hero">
      <text class="title">苏迪 AI 购物</text>
      <text class="desc">告诉我你想买什么，我只从商城真实在售商品里帮你找。</text>\n      <view class="quick-row">\n        <button v-for="item in quickPrompts" :key="item" class="quick-btn" @click="usePrompt(item)">{{ item }}</button>\n      </view>
    </view>

    <view class="ask-box">
      <textarea v-model="query" maxlength="200" placeholder="例如：帮我找200元以内的黑色外套" />
      <button class="ask-btn" :disabled="loading" @click="search">{{ loading ? '正在找商品…' : '帮我找商品' }}</button>
    </view>

    <view v-if="error" class="message">{{ error }}</view>
    <view v-if="searched && !loading && !products.length" class="empty">暂时没有找到合适商品，换个说法试试。</view>\n\n    <view v-if="products.length" class="result-head">为你找到 {{ products.length }} 件商品</view>

    <view v-for="item in products" :key="item.id" class="product" @click="openProduct(item.id)">
      <image :src="item.image" mode="aspectFill" />
      <view class="info">
        <text class="name">{{ item.store_name }}</text>
        <text class="price">¥{{ item.price }}</text>
        <text class="stock">库存 {{ item.stock || 0 }} · 已售 {{ item.sales || 0 }}</text>
      </view>
    </view>
  </view>
</template>

<script>
import { sudiAiShopping } from "@/api/sudi_ai.js";

export default {
  data() {
    return { query: "", loading: false, searched: false, products: [], error: "", quickPrompts: ["200元以内外套", "销量好的裤子", "针织开衫", "黑色上衣"] };
  },
  methods: {\n    usePrompt(text) {\n      this.query = text;\n      this.search();\n    },
    async search() {
      const query = (this.query || "").trim();
      if (!query) {
        uni.showToast({ title: "先告诉我你想买什么", icon: "none" });
        return;
      }
      this.loading = true;
      this.searched = true;
      this.error = "";
      this.products = [];
      try {
        const res = await sudiAiShopping({ query, page: 1, limit: 20 });
        const data = res && res.data ? res.data : {};
        this.products = Array.isArray(data.products) ? data.products : [];
      } catch (e) {
        this.error = "AI购物暂时不可用，你仍然可以正常浏览商城商品。";
      } finally {
        this.loading = false;
      }
    },
    openProduct(id) {
      if (!id) return;
      uni.navigateTo({ url: "/pages/goods_details/index?id=" + id });
    }
  }
};
</script>

<style scoped>
.sudi-ai-page{min-height:100vh;background:#f7f7f7;padding:28rpx;box-sizing:border-box}
.hero,.ask-box,.product,.message,.empty{background:#fff;border-radius:20rpx}
.hero{padding:34rpx;margin-bottom:22rpx}
.title{display:block;font-size:42rpx;font-weight:700;color:#222}
.desc{display:block;margin-top:14rpx;font-size:26rpx;line-height:1.6;color:#777}\n.quick-row{display:flex;flex-wrap:wrap;gap:12rpx;margin-top:22rpx}\n.quick-btn{margin:0;padding:0 22rpx;height:58rpx;line-height:58rpx;border-radius:999rpx;background:#f7f7f7;color:#555;font-size:23rpx}\n.result-head{padding:4rpx 4rpx 18rpx;font-size:25rpx;color:#777}
.ask-box{padding:24rpx;margin-bottom:24rpx}
textarea{width:100%;height:150rpx;background:#f7f7f7;border-radius:14rpx;padding:20rpx;box-sizing:border-box;font-size:28rpx}
.ask-btn{margin-top:18rpx;background:#ff3366;color:#fff;border-radius:999rpx;font-size:28rpx}
.ask-btn[disabled]{opacity:.6}
.product{display:flex;padding:18rpx;margin-bottom:18rpx}
.product image{width:190rpx;height:190rpx;border-radius:14rpx;background:#eee;flex:none}
.info{min-width:0;padding:8rpx 0 8rpx 22rpx;display:flex;flex-direction:column}
.name{font-size:29rpx;line-height:1.45;color:#222}
.price{margin-top:auto;font-size:34rpx;font-weight:700;color:#ff3366}
.stock{margin-top:8rpx;font-size:23rpx;color:#999}
.message,.empty{padding:28rpx;margin-bottom:20rpx;color:#777;font-size:26rpx;line-height:1.5}
</style>
