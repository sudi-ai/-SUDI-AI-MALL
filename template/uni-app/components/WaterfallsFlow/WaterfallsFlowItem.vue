<template>
	<view class="wf-item-page wf-page0">
		<view class='pictrue'>
			<easy-loadimage
			mode="aspectFit"
			:image-src="item.image"
			width="100%"
			height="100%"
			borderRadius="0"></easy-loadimage>
		</view>
		<view class="info_box">
          <view class="nu-product-name line2"><text v-if="item.brand_name" class="brand-tag">{{ item.brand_name }}</text>{{ item.store_name }}</view>
          <view class="nu-product-bottom">
            <text class="nu-product-price">¥{{ item.price }}</text>
            <view v-if="goDetail === 'goDetail'" class="nu-product-action" aria-label="选择款式和尺码"><text>↗</text></view>
            <view v-else class="nu-product-action" aria-label="加入购物车" @tap.stop="addCartChange"><text>＋</text></view>
          </view>
          <view v-if="Number(item.vip_price) > 0" class="nu-member-price">会员价 ¥{{ item.vip_price }}</view>
        </view>
	</view>
</template>
<script>
	import easyLoadimage from '@/components/easy-loadimage/easy-loadimage.vue'
	import {mapGetters} from "vuex";
	import {HTTP_REQUEST_URL} from '@/config/app';
	export default {
		components: {
			easyLoadimage
		},
		props: {
			item: {
				type: Object,
				require: true
			},
			type: {
				type: Number,
				default: 0
			},
			recommend:{
				type: Boolean,
				default: false
			},
			goDetail: {
				type: String,
				default: ''
			}
		},
		data() {
			return {
				domain: HTTP_REQUEST_URL
			}
		},
		methods: {
			addCartChange(){
				this.$eventHub.$emit('onCartAddChange',this.item);
			},
		}
	}
</script>
<style lang="scss" scoped>
    .pictrue { position:relative;width:100%;height:0;padding-bottom:133.333333%;background:#f0eeea; }
    .pictrue ::v-deep .easy-loadimage { position:absolute;inset:0; }
    .nu-product-name { height:82.5rpx; }
    .nu-product-bottom { display:flex;align-items:center;justify-content:space-between;gap:8rpx;margin-top:8rpx; }
    .nu-product-price { font-size:29rpx;font-weight:400;letter-spacing:0; }
    .nu-product-action { min-width:72rpx;min-height:72rpx;display:flex;align-items:center;justify-content:flex-end;font-size:30rpx; }
    .nu-member-price { font-size:20rpx;color:#77776e; }
	.wf-item-page {
		background: #fff;
		overflow: hidden;
		border-radius: 20rpx;
	}
	.info_box{
		padding: 16rpx 20rpx;
		border-radius: 0 0 20rpx 20rpx;
		background-color: #fff;
	}
	.text-primary-con{
		color: var(--view-theme);
	}
	.bg-primary-light{
		background: var(--view-minorColorT);
	}
	.bg--w111-484643{
		background: linear-gradient(90deg, #484643 0%, #1F1B17 100%);
	}
	.text--w111-FDDAA4{
		color: #FDDAA4;
	}
	.svip_rd{
		border-radius: 14rpx 0 8rpx 14rpx;
	}
</style>
