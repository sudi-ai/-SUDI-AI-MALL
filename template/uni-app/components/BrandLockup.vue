<template>
  <view class="brand-lockup" :class="{ compact, centered }">
    <view class="brand-name">{{ brand.name }}</view>
    <view class="brand-company">{{ brand.company }}</view>
  </view>
</template>
<script>
import { brand } from '@/utils/brand';
export default {
  props: { compact: Boolean, centered: Boolean },
  data() { return { brand }; }
};
</script>
<style scoped>
.brand-lockup { color:#1c1d1a; }
.brand-name { font-family:Didot,'Bodoni MT','Times New Roman',serif;font-size:76rpx;font-weight:400;letter-spacing:-4rpx;line-height:1.15; }
.brand-company { margin-top:18rpx;font-size:18rpx;letter-spacing:3rpx;color:#77776e;line-height:1.7; }
.compact .brand-name { font-size:56rpx;letter-spacing:-2rpx; }
.compact .brand-company { font-size:17rpx;margin-top:10rpx;letter-spacing:2rpx; }
.centered { text-align:center; }
</style>
