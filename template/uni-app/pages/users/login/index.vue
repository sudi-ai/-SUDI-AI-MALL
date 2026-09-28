<template>
	<view class="login-wrapper" :style="colorStyle">
		<view class="login-orbit" aria-hidden="true"></view>
		<BrandLockup class="login-brand" />
		<view class="login-heading">欢迎回来</view>
		<view class="login-description">登录后继续探索本季新作</view>
		<view class="whiteBg" v-if="formItem === 1">
            <view class="login-tabs" v-if="!appLoginStatus && !appleLoginStatus">
                <button class="login-tab" :class="{ active: current === 1 }" @click="current = 1">验证码登录</button>
                <button class="login-tab" :class="{ active: current === 0 }" @click="current = 0">账号登录</button>
            </view>
			<view class="list" v-if="current !== 1">
				<form @submit.prevent="submit">
					<view class="item">
						<view class="acea-row row-middle">
							<image src="../static/phone_1.png" style="width: 24rpx; height: 34rpx"></image>
							<input type="text" :placeholder="$t(`手机号、邮箱或账号`)" v-model="account" maxlength="100" required />
						</view>
					</view>
					<view class="item">
						<view class="acea-row row-middle">
							<image src="../static/code_1.png" style="width: 28rpx; height: 32rpx"></image>
							<input type="password" :placeholder="$t(`填写登录密码`)" v-model="password" required />
						</view>
					</view>
				</form>
				<!-- <navigator class="forgetPwd" hover-class="none" url="/pages/users/retrievePassword/index">
					<span class="iconfont icon-wenti"></span>忘记密码
				</navigator> -->
			</view>
			<view class="list" v-if="current !== 0 || appLoginStatus || appleLoginStatus">
				<view class="item">
					<view class="acea-row row-middle">
						<image src="../static/phone_1.png" style="width: 24rpx; height: 34rpx"></image>
						<input type="number" inputmode="tel" :placeholder="$t(`请输入手机号`)" v-model.trim="account" :maxlength="11" />
					</view>
				</view>
				<view class="item">
					<view class="acea-row row-middle">
						<image src="../static/code_2.png" style="width: 28rpx; height: 32rpx"></image>
						<input type="number" inputmode="numeric" text-content-type="one-time-code" :placeholder="$t(`请输入验证码`)" :maxlength="6" class="codeIput" v-model.trim="captcha" />
						<button class="code" :disabled="disabled" :class="disabled === true ? 'on' : ''" @click="code">
							{{ text }}
						</button>
					</view>
				</view>
				<!-- 	<view class="item" v-if="isShowCode">
					<view class="acea-row row-middle">
						<image src="../static/code_2.png" style="width: 28rpx; height: 32rpx;"></image>
						<input type="text" :placeholder="$t(`请输入验证码`)" class="codeIput" v-model="codeVal" />
						<view class="code" @click="again"><img :src="codeUrl" /></view>
					</view>
				</view> -->
			</view>
			<button class="logon" @click="loginMobile" v-if="current !== 0">{{ $t(`登录 / 注册`) }}</button>
			<button class="logon" @click="submit" v-if="current === 0">{{ $t(`登录`) }}</button>

			<!-- #ifdef APP-PLUS -->
			<view class="appLogin" v-if="!appLoginStatus && !appleLoginStatus">
				<view class="hds">
					<span class="line"></span>
					<p>{{ $t(`其他方式登录`) }}</p>
					<span class="line"></span>
				</view>
				<view class="btn-wrapper">
					<view class="btn wx" @click="wxLogin">
						<span class="iconfont icon-s-weixindenglu1"></span>
					</view>
					<view class="btn mima" v-if="current == 1" @click="current = 0">
						<span class="iconfont icon-s-mimadenglu1"></span>
					</view>
					<view class="btn yanzheng" v-if="current == 0" @click="current = 1">
						<span class="iconfont icon-s-yanzhengmadenglu1"></span>
					</view>
					<view class="apple-btn" @click="appleLogin" v-if="appleShow">
						<view class="iconfont icon-s-pingguo"></view>
					</view>
				</view>
			</view>
			<!-- #endif -->
			<view class="phone-register-hint" v-if="current !== 0 && !appLoginStatus && !appleLoginStatus">未注册手机号验证通过后将自动创建账号</view>
			<view class="protocol">
				<checkbox-group @change="ChangeIsDefault">
					<checkbox :class="inAnimation ? 'trembling' : ''" @animationend="inAnimation = false" :checked="protocol ? true : false" />
					{{ $t(`已阅读并同意`) }}
					<text class="main-color" @click="privacy(4)">{{ $t(`《用户协议》`) }}</text>
					{{ $t(`与`) }}
					<text class="main-color" @click="privacy(3)">{{ $t(`《隐私政策》`) }}</text>
				</checkbox-group>
			</view>
		</view>
		<view class="brand-footer">
            <view>{{ $brand.signature }}</view>
            <view class="legal-copyright" v-if="copyRight">{{ copyRight }}</view>
        </view>
		<Verify @success="success" :captchaType="captchaType" :imgSize="{ width: '330px', height: '155px' }" ref="verify"></Verify>
	</view>
</template>
<script>
import dayjs from '@/plugin/dayjs/dayjs.min.js';
import sendVerifyCode from '@/mixins/SendVerifyCode';
import { loginH5, loginMobile, registerVerify, register, getCodeApi, getUserInfo, appleLogin } from '@/api/user';
import attrs, { required, alpha_num, chs_phone } from '@/utils/validate';
import { getLogo } from '@/api/public';
// import cookie from "@/utils/store/cookie";
import { VUE_APP_API_URL } from '@/utils';
// #ifdef APP-PLUS
import { wechatAppAuth } from '@/api/api.js';
// #endif
const BACK_URL = 'login_back_url';
import colors from '@/mixins/color.js';
import Verify from '../components/verify/index.vue';
import Cache from '@/utils/cache';

export default {
	name: 'Login',
	components: {
		Verify
	},
	mixins: [sendVerifyCode, colors],
	data: function () {
		return {
			copyRight: '',
			inAnimation: false,
			protocol: false,
			navList: [this.$t(`快速登录`), this.$t(`账号登录`)],
			current: 1,
			account: '',
			password: '',
			captcha: '',
			formItem: 1,
			type: 'login',
			logoUrl: '',
			keyCode: '',
			codeUrl: '',
			codeVal: '',
			isShowCode: false,
			appLoginStatus: false, // 微信登录强制绑定手机号码状态
			appUserInfo: null, // 微信登录保存的用户信息
			appleLoginStatus: false, // 苹果登录强制绑定手机号码状态
			appleUserInfo: null,
			appleShow: false, // 苹果登录版本必须要求ios13以上的
			keyLock: true,
			captchaType: 'clickWord',
			configData: Cache.get('BASIC_CONFIG')
		};
	},
	watch: {
		formItem: function (nval, oVal) {
			if (nval == 1) {
				this.type = 'login';
			} else {
				this.type = 'register';
			}
		}
	},
	onLoad() {
		let self = this;
		uni.getSystemInfo({
			success: (res) => {
				if (res.platform.toLowerCase() == 'ios' && this.getSystem(res.system)) {
					self.appleShow = true;
				}
			}
		});
		if (uni.getStorageSync('copyRight').copyrightContext) {
			this.copyRight = uni.getStorageSync('copyRight').copyrightContext;
		}
	},
	mounted() {
		// this.getCode();
		this.getLogoImage();
	},
	methods: {
		ChangeIsDefault(e) {
			this.$set(this, 'protocol', !this.protocol);
		},
		privacy(type) {
			uni.navigateTo({
				url: '/pages/users/privacy/index?type=' + type
			});
		},
		// IOS 版本号判断
		getSystem(system) {
			let str;
			system.toLowerCase().indexOf('ios') === -1 ? (str = system) : (str = system.split(' ')[1]);
			if (str.indexOf('.')) return str.split('.')[0] >= 13;
			return str >= 13;
		},
		// 苹果登录
		appleLogin() {
			let self = this;
			this.account = '';
			this.captcha = '';
			if (!self.protocol) {
				this.inAnimation = true;
				return self.$util.Tips({
					title: '请先阅读并同意协议'
				});
			}
			uni.showLoading({
				title: this.$t(`登录中`)
			});
			uni.login({
				provider: 'apple',
				timeout: 10000,
				success(loginRes) {
					uni.getUserInfo({
						provider: 'apple',
						success: function (infoRes) {
							self.appleUserInfo = infoRes.userInfo;
							self.appleLoginApi();
						},
						fail() {
							uni.showToast({
								title: self.$t(`获取用户信息失败`),
								icon: 'none',
								duration: 2000
							});
						},
						complete() {
							uni.hideLoading();
						}
					});
				},
				fail(error) {
					console.log(error);
				}
			});
		},
		// 苹果登录Api
		appleLoginApi() {
			let self = this;
			appleLogin({
				openId: self.appleUserInfo.openId,
				email: self.appleUserInfo.email || '',
				phone: this.account,
				captcha: this.captcha
			})
				.then(({ data }) => {
					if (data.isbind) {
						uni.showModal({
							title: self.$t(`提示`),
							content: self.$t(`请绑定手机号后，继续操作`),
							showCancel: false,
							success: function (res) {
								if (res.confirm) {
									self.current = 1;
									self.appleLoginStatus = true;
								}
							}
						});
					} else {
						self.$store.commit('LOGIN', {
							token: data.token,
							time: data.expires_time - self.$Cache.time()
						});
						let backUrl = self.$Cache.get(BACK_URL) || '/pages/index/index';
						self.$Cache.clear(BACK_URL);
						self.$store.commit('SETUID', data.userInfo.uid);
						uni.reLaunch({
							url: backUrl
						});
					}
				})
				.catch((error) => {
					uni.showModal({
						title: self.$t(`提示`),
						content: self.$t(`错误信息`) + `${error}`,
						success: function (res) {
							if (res.confirm) {
								console.log(self.$t(`用户点击确定`));
							} else if (res.cancel) {
								console.log(self.$t(`用户点击取消`));
							}
						}
					});
				});
		},
		// App微信登录
		wxLogin() {
			let self = this;
			this.account = '';
			this.captcha = '';
			if (!self.protocol) {
				this.inAnimation = true;
				return self.$util.Tips({
					title: '请先阅读并同意协议'
				});
			}
			uni.showLoading({
				title: self.$t(`登录中`)
			});
			uni.login({
				provider: 'weixin',
				success: function (loginRes) {
					// 获取用户信息
					uni.getUserInfo({
						provider: 'weixin',
						success: function (infoRes) {
							self.appUserInfo = infoRes.userInfo;
							self.wxLoginApi();
						},
						fail() {
							uni.showToast({
								title: self.$t(`获取用户信息失败`),
								icon: 'none',
								duration: 2000
							});
						},
						complete() {
							uni.hideLoading();
						}
					});
				},
				fail() {
					uni.showToast({
						title: self.$t(`登录失败`),
						icon: 'none',
						duration: 2000
					});
				}
			});
		},

		wxLoginApi() {
			let self = this;
			wechatAppAuth({
				userInfo: self.appUserInfo,
				phone: this.account,
				code: this.captcha
			})
				.then(({ data }) => {
					if (data.isbind) {
						uni.showModal({
							title: self.$t(`提示`),
							content: self.$t(`请绑定手机号后，继续操作`),
							showCancel: false,
							success: function (res) {
								if (res.confirm) {
									self.current = 1;
									self.appLoginStatus = true;
								}
							}
						});
					} else {
						self.$store.commit('LOGIN', {
							token: data.token,
							time: data.expires_time - self.$Cache.time()
						});
						let backUrl = self.$Cache.get(BACK_URL) || '/pages/index/index';
						self.$Cache.clear(BACK_URL);
						self.$store.commit('SETUID', data.userInfo.uid);
						uni.reLaunch({
							url: backUrl
						});
					}
				})
				.catch((error) => {
					uni.showModal({
						title: self.$t(`提示`),
						content: self.$t(`错误信息`) + `${error}`,
						success: function (res) {
							if (res.confirm) {
								console.log(self.$t(`用户点击确定`));
							} else if (res.cancel) {
								console.log(self.$t(`用户点击取消`));
							}
						}
					});
				});
		},
		again() {
			this.codeUrl = VUE_APP_API_URL + '/sms_captcha?' + 'key=' + this.keyCode + Date.parse(new Date());
		},
		success(data) {
			this.$refs.verify.hide();
			getCodeApi()
				.then((res) => {
					this.keyCode = res.data.key;
					this.getCode(data);
				})
				.catch((res) => {
					this.$util.Tips({
						title: res
					});
				});
		},
		code() {
			let that = this;
			if (!that.protocol) {
				this.inAnimation = true;
				return that.$util.Tips({
					title: '请先阅读并同意协议'
				});
			}
			if (!that.account)
				return that.$util.Tips({
					title: that.$t(`请填写手机号码`)
				});
			if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(that.account))
				return that.$util.Tips({
					title: that.$t(`请输入正确的手机号码`)
				});
			this.$refs.verify.show();
		},
		async getLogoImage() {
			let that = this;
			getLogo(2).then((res) => {
				that.logoUrl = res.data.logo_url;
			});
		},
		async loginMobile() {
			let that = this;
			if (!that.protocol) {
				this.inAnimation = true;
				return that.$util.Tips({
					title: '请先阅读并同意协议'
				});
			}
			if (!that.account)
				return that.$util.Tips({
					title: that.$t(`请填写手机号码`)
				});
			if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(that.account))
				return that.$util.Tips({
					title: that.$t(`请输入正确的手机号码`)
				});
			if (!that.captcha)
				return that.$util.Tips({
					title: that.$t(`请请输入验证码`)
				});
			if (!/^\d{6}$/.test(that.captcha))
				return that.$util.Tips({
					title: that.$t(`请输入正确的验证码`)
				});
			if (that.appLoginStatus) {
				that.wxLoginApi();
			} else if (that.appleLoginStatus) {
				that.appleLoginApi();
			} else {
				if (this.keyLock) {
					this.keyLock = !this.keyLock;
				} else {
					return that.$util.Tips({
						title: that.$t(`请勿重复点击`)
					});
				}
				loginMobile({
					phone: that.account,
					captcha: that.captcha,
					spread: that.$Cache.get('spread'),
					agent_id: that.$Cache.get('agent_id') || 0
				})
					.then((res) => {
						let data = res.data;
						that.$store.commit('LOGIN', {
							token: data.token,
							time: data.expires_time - this.$Cache.time()
						});
						let backUrl = that.$Cache.get(BACK_URL) || '/pages/index/index';
						that.$Cache.clear(BACK_URL);
						return getUserInfo().then((res) => {
							this.keyLock = true;
							that.$store.commit('SETUID', res.data.uid);
							if (backUrl.indexOf('/pages/users/login/index') !== -1) {
								backUrl = '/pages/index/index';
							}
							if (data.needs_password_setup) {
								that.$Cache.set('password_setup_back', backUrl, 600);
								return uni.reLaunch({ url: '/pages/users/user_pwd_edit/index?setup=1' });
							}
							uni.reLaunch({
								url: backUrl
							});
						});
					})
					.catch((res) => {
						this.keyLock = true;
						that.$util.Tips({
							title: res
						});
					});
			}
		},
		async register() {
			let that = this;
			if (!that.protocol) {
				this.inAnimation = true;
				return that.$util.Tips({
					title: '请先阅读并同意协议'
				});
			}
			if (!that.account)
				return that.$util.Tips({
					title: that.$t(`请填写手机号码`)
				});
			if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(that.account))
				return that.$util.Tips({
					title: that.$t(`请输入正确的手机号码`)
				});
			if (!that.captcha)
				return that.$util.Tips({
					title: that.$t(`请请输入验证码`)
				});
			if (!/^[\w\d]+$/i.test(that.captcha))
				return that.$util.Tips({
					title: that.$t(`请输入正确的验证码`)
				});
			if (!that.password)
				return that.$util.Tips({
					title: that.$t(`请填写密码`)
				});
			if (/^([0-9]|[a-z]|[A-Z]){0,6}$/i.test(that.password))
				return that.$util.Tips({
					title: that.$t(`您输入的密码过于简单`)
				});
			register({
				account: that.account,
				captcha: that.captcha,
				password: that.password,
				spread: that.$Cache.get('spread')
			})
				.then((res) => {
					that.$util.Tips({
						title: res
					});
					that.formItem = 1;
				})
				.catch((res) => {
					that.$util.Tips({
						title: res
					});
				});
		},
		async getCode(data) {
			let that = this;
			if (!that.protocol) {
				this.inAnimation = true;
				return that.$util.Tips({
					title: '请先阅读并同意协议'
				});
			}
			if (!that.account)
				return that.$util.Tips({
					title: that.$t(`请填写手机号码`)
				});
			if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(that.account))
				return that.$util.Tips({
					title: that.$t(`请输入正确的手机号码`)
				});
			if (that.formItem == 2) that.type = 'register';

			await registerVerify({
				phone: that.account,
				type: that.type,
				key: that.keyCode,
				captchaType: this.captchaType,
				captchaVerification: data.captchaVerification
			})
				.then((res) => {
					this.sendCode();
					that.$util.Tips({
						title: res.msg
					});
				})
				.catch((res) => {
					that.$util.Tips({
						title: res
					});
				});
		},
		navTap: function (index) {
			this.current = index;
		},
		async submit() {
			let that = this;
			if (!that.protocol) {
				this.inAnimation = true;
				return that.$util.Tips({
					title: '请先阅读并同意协议'
				});
			}
			if (!that.account)
				return that.$util.Tips({
					title: that.$t(`请填写账号`)
				});
			if (!/^[\w\d]{5,16}$/i.test(that.account) && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(that.account))
				return that.$util.Tips({
					title: that.$t(`请输入正确的账号`)
				});
			if (!that.password)
				return that.$util.Tips({
					title: that.$t(`请填写密码`)
				});
			if (this.keyLock) {
				this.keyLock = !this.keyLock;
			} else {
				return that.$util.Tips({
					title: that.$t(`请勿重复点击`)
				});
			}
			loginH5({
				account: that.account,
				password: that.password,
				spread: that.$Cache.get('spread'),
				agent_id: that.$Cache.get('agent_id') || 0
			})
				.then(({ data }) => {
					that.$store.commit('LOGIN', {
						token: data.token,
						time: data.expires_time - this.$Cache.time()
					});
					let backUrl = that.$Cache.get(BACK_URL) || '/pages/index/index';
					that.$Cache.clear(BACK_URL);
					getUserInfo()
						.then((res) => {
							this.keyLock = true;
							that.$store.commit('SETUID', res.data.uid);
							uni.reLaunch({
								url: backUrl
							});
						})
						.catch((error) => {
							this.keyLock = true;
						});
				})
				.catch((e) => {
					this.keyLock = true;
					that.$util.Tips({
						title: e
					});
				});
		}
	}
};
</script>
<style scoped lang="scss">
.login-wrapper {
    position:relative;display:flex;flex-direction:column;overflow:hidden;
    min-height:100vh;min-height:100svh;padding:140rpx 64rpx 30rpx;
    padding-top:calc(140rpx + env(safe-area-inset-top));
    padding-bottom:calc(30rpx + env(safe-area-inset-bottom));
    background:#f8f6f2;color:#1c1d1a;box-sizing:border-box;
}
.login-brand,.login-heading,.login-description,.whiteBg,.brand-footer { position:relative;z-index:1; }
.login-orbit { position:absolute;width:310rpx;height:310rpx;border:1px solid #cbb8b1;border-radius:50%;right:-160rpx;top:130rpx;pointer-events:none; }
.login-orbit::after { content:'';position:absolute;inset:36rpx;border:1px solid #dfd1ca;border-radius:50%; }
.login-heading { margin-top:66rpx;font-size:34rpx;line-height:1.4;font-weight:600;letter-spacing:1rpx; }
.login-description { margin-top:18rpx;color:#77776e;font-size:22rpx;line-height:1.7; }
.whiteBg { margin-top:44rpx; }
.login-tabs { display:flex;gap:40rpx;border-bottom:1px solid #d6d3cc; }
.login-tab { margin:0;padding:0 0 20rpx;background:transparent;color:#77776e;font-size:23rpx;line-height:1.5;min-height:60rpx;border-radius:0; }
.login-tab::after,.logon::after,.code::after { border:0; }
.login-tab.active { color:#1c1d1a;font-weight:600;box-shadow:0 1px 0 #1c1d1a; }
.list .item { border-bottom:1px solid #d6d3cc;background:transparent; }
.list .row-middle { min-height:102rpx;display:flex;align-items:center;gap:24rpx; }
.list image { opacity:.7;flex:none;filter:grayscale(1); }
.list input { min-width:0;flex:1;height:100rpx;font-size:25rpx;margin:0; }
.list .code { flex:none;background:transparent;color:#1c1d1a;font-size:21rpx;margin:0;padding:0;min-height:76rpx;line-height:76rpx; }
.list .code.on { color:#77776e; }
.logon { display:flex;align-items:center;justify-content:center;width:100%;height:88rpx;margin:44rpx 0 0;border-radius:0;background:#1c1d1a;color:#fff;font-size:26rpx;letter-spacing:2rpx;font-weight:600; }
.phone-register-hint { color:#77776e;font-size:19rpx;line-height:1.7;margin-top:20rpx; }
.protocol { margin-top:12rpx;color:#77776e;font-size:19rpx;line-height:1.9; }
.protocol checkbox { transform:scale(.65);transform-origin:left center;width:28rpx;vertical-align:middle; }
.main-color { color:#62645d;text-decoration:underline;text-underline-offset:4rpx; }
.brand-footer { margin-top:auto;padding-top:82rpx;text-align:center;color:#77776e;font-size:16rpx;letter-spacing:2.5rpx;line-height:1.8; }
.legal-copyright { margin-top:10rpx;font-size:15rpx;letter-spacing:0; }
.appLogin { margin-top:30rpx; }
.appLogin .hds,.appLogin .btn-wrapper { display:flex;justify-content:center;gap:24rpx;align-items:center;font-size:22rpx; }
.appLogin .btn-wrapper { margin-top:22rpx; }
.appLogin .btn,.appLogin .apple-btn { width:72rpx;height:72rpx;border:1px solid #d6d3cc;border-radius:50%;display:flex;align-items:center;justify-content:center; }
.appLogin .iconfont { font-size:38rpx; }
.trembling { animation:shake .6s; }
@media (max-height:650px) { .login-wrapper { padding-top:60rpx; } .login-heading { margin-top:38rpx; } .brand-footer { padding-top:44rpx; } }
@media (min-width:600px) { .login-wrapper { max-width:480px;margin:auto;padding:72px 40px 24px; } }
</style>
