<template>
	<view :style="colorStyle">
		<view class="ChangePassword">
			<form @submit="editPwd">
				<view class="phone">{{$t(`当前手机号`)}}：{{phone}}</view>
				<view class="password-hint">{{ setupMode ? '注册成功！设置密码后，下次可选择密码或验证码登录。' : '设置后可使用当前手机号和密码登录。' }}</view>
				<view class="password-hint">密码为8到32位，需包含字母和数字</view>
				<view class="list">
					<view class="item">
						<input type='password' :placeholder='$t(`设置新密码`)' placeholder-class='placeholder'
							name="password" maxlength="32" v-model="password"></input>
					</view>
					<view class="item">
						<input type='password' :placeholder='$t(`确认新密码`)' placeholder-class='placeholder'
							name="qr_password" maxlength="32" v-model="qr_password"></input>
					</view>
					<view class="item acea-row row-between-wrapper" v-if="!setupMode">
						<input type='number' :placeholder='$t(`填写验证码`)' placeholder-class='placeholder' class="codeIput"
							name="captcha" maxlength="6" inputmode="numeric" text-content-type="one-time-code" v-model.trim="captcha"></input>
						<button class="code font-num" :class="disabled === true ? 'on' : ''" :disabled='disabled'
							@click="code">
							{{ text }}
						</button>
					</view>
				</view>
				<button form-type="submit" class="confirmBnt bg-color" :disabled="saving">{{ saving ? '保存中…' : '保存密码' }}</button>
				<view v-if="onboarding" class="skip-password" @click="continueShopping">稍后设置，继续购物</view>
			</form>
		</view>
		<!-- #ifdef MP -->
		<!-- <authorize @onLoadFun="onLoadFun" :isAuto="isAuto" :isShowAuth="isShowAuth" @authColse="authColse"></authorize> -->
		<!-- #endif -->
		<Verify @success="success" :captchaType="captchaType" :imgSize="{ width: '330px', height: '155px' }"
			ref="verify"></Verify>
	</view>
</template>

<script>
	import sendVerifyCode from "@/mixins/SendVerifyCode";
	import {
		phoneRegisterReset,
		verifyCode
	} from '@/api/api.js';
	import {
		getUserInfo,
		setupLoginPassword,
		registerVerify
	} from '@/api/user.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from "vuex";
	// #ifdef MP
	import authorize from '@/components/Authorize';
	// #endif
	import colors from '@/mixins/color.js';
	import Verify from '../components/verify/index.vue';
	export default {
		mixins: [sendVerifyCode, colors],
		components: {
			// #ifdef MP
			authorize,
			// #endif
			Verify
		},
		data() {
			return {
				userInfo: {},
				phone: '',
				password: '',
				captcha: '',
				qr_password: '',
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				key: '',
				setupMode: false,
				onboarding: false,
				saving: false,
			};
		},
		computed: mapGetters(['isLogin']),
		watch: {
			isLogin: {
				handler: function(newV, oldV) {
					if (newV) {
						this.getUserInfo();
					}
				},
				deep: true
			}
		},
		onLoad(options) {
			this.setupMode = options.setup === '1';
			this.onboarding = this.setupMode;
			uni.setNavigationBarTitle({ title: this.setupMode ? '设置登录密码' : '设置或修改密码' });
			if (this.isLogin) {
				this.getUserInfo();
				if (!this.setupMode) this.prepareVerifyKey();
			} else {
				toLogin()
			}
		},
		methods: {
			prepareVerifyKey() {
				verifyCode().then(res => { this.key = res.data.key; }).catch(err => this.$util.Tips({ title: err }));
			},
			continueShopping() {
				if (this.saving) return;
				let back = this.$Cache.get('password_setup_back') || '/pages/index/index';
				this.$Cache.clear('password_setup_back');
				if (typeof back !== 'string' || !back.startsWith('/pages/') || /users\/(login|user_pwd_edit)/.test(back)) back = '/pages/index/index';
				uni.reLaunch({ url: back });
			},
			/**
			 * 授权回调
			 */
			onLoadFun: function(e) {
				this.getUserInfo();
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			/**
			 * 获取个人用户信息
			 */
			getUserInfo: function() {
				let that = this;
				getUserInfo().then(res => {
					let tel = res.data.phone || '';
					let phone = tel.substr(0, 3) + "****" + tel.substr(7);
					that.$set(that, 'userInfo', res.data);
					that.phone = phone;
				});
			},
			/**
			 * 发送验证码
			 * 
			 */
			async code() {
				let that = this;
				if (!that.userInfo.phone) return that.$util.Tips({
					title: that.$t(`手机号码不存在,无法发送验证码！`)
				});
				this.$refs.verify.show()

			},
			async success(data) {
				let that = this;
				this.$refs.verify.hide()
				await registerVerify({
					phone: that.userInfo.phone,
					type: 'reset',
					key: that.key,
					captchaType: this.captchaType,
					captchaVerification: data.captchaVerification
				}).then(res => {
					this.sendCode()
					that.$util.Tips({
						title: res.msg
					});
				}).catch(err => {
					return that.$util.Tips({
						title: err
					});
				});
			},
			/**
			 * H5登录 修改密码
			 * 
			 */
			editPwd: function(e) {
				if (this.saving) return;
				let that = this,
					password = e.detail.value.password,
					qr_password = e.detail.value.qr_password,
					captcha = e.detail.value.captcha;
				if (!password) return that.$util.Tips({
					title: that.$t(`请输入新密码`)
				});
				if (qr_password != password) return that.$util.Tips({
					title: that.$t(`两次输入的密码不一致！`)
				});
				if (!/^(?=.*[A-Za-z])(?=.*\d)\S{8,32}$/.test(password)) return that.$util.Tips({ title: '请设置8到32位包含字母和数字的密码' });
				if (!that.setupMode && !/^\d{6}$/.test(captcha || '')) return that.$util.Tips({
					title: that.$t(`请输入验证码`)
				});
				that.saving = true;
				const save = that.setupMode ? setupLoginPassword({ password, password_confirm: qr_password }) : phoneRegisterReset({
					account: that.userInfo.phone,
					captcha: captcha,
					password: password
				});
				save.then(res => {
					that.saving = false;
					that.password = '';
					that.qr_password = '';
					that.captcha = '';
					if (that.onboarding) {
						uni.showToast({ title: '密码已设置', icon: 'success' });
						return that.continueShopping();
					}
					return that.$util.Tips({
						title: res.msg
					}, {
						tab: 3,
						url: 1
					});
				}).catch(err => {
					that.saving = false;
					if (that.setupMode && String(err).includes('重新获取短信验证码')) {
						that.setupMode = false;
						that.prepareVerifyKey();
					}
					return that.$util.Tips({
						title: err
					});
				});
			}
		}
	}
</script>

<style lang="scss">
	page {
		background-color: #fff !important;
	}

	.ChangePassword .phone {
		font-size: 32rpx;
		font-weight: bold;
		text-align: center;
		margin-top: 55rpx;
	}
	.password-hint { margin: 24rpx 48rpx 0; color: #777; font-size: 26rpx; line-height: 1.6; text-align: center; }
	.skip-password { margin: 36rpx 0; color: #777; font-size: 28rpx; text-align: center; }

	.ChangePassword .list {
		width: 580rpx;
		margin: 53rpx auto 0 auto;
	}

	.ChangePassword .list .item {
		width: 100%;
		height: 110rpx;
		border-bottom: 2rpx solid #f0f0f0;
	}

	.ChangePassword .list .item input {
		width: 100%;
		height: 100%;
		font-size: 32rpx;
	}

	.ChangePassword .list .item .placeholder {
		color: #b9b9bc;
	}

	.ChangePassword .list .item input.codeIput {
		width: 340rpx;
	}

	.ChangePassword .list .item .code {
		font-size: 32rpx;
		background-color: #fff;
	}

	.ChangePassword .list .item .code.on {
		color: #b9b9bc !important;
	}

	.ChangePassword .confirmBnt {
		font-size: 32rpx;
		width: 580rpx;
		height: 90rpx;
		border-radius: 45rpx;
		color: #fff;
		margin: 92rpx auto 0 auto;
		text-align: center;
		line-height: 90rpx;
	}
</style>
