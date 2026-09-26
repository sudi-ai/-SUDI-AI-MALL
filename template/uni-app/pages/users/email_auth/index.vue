<template>
	<view class="email-auth">
		<view class="title">邮箱注册</view>
		<view class="field"><input v-model.trim="email" type="text" maxlength="100" placeholder="请输入邮箱地址" /></view>
		<view class="field code-row">
			<input v-model.trim="captcha" type="number" maxlength="6" placeholder="邮箱验证码" />
			<button :disabled="countdown > 0 || sending" @click="sendCode">{{ countdown ? countdown + '秒后重试' : '获取验证码' }}</button>
		</view>
		<view class="field"><input v-model="password" password maxlength="72" placeholder="设置密码（8位以上）" /></view>
		<checkbox-group class="protocol" @change="protocol = !protocol">
			<checkbox :checked="protocol" />我已阅读并同意用户协议与隐私协议
		</checkbox-group>
		<button class="submit" :disabled="submitting" @click="submit">注册并登录</button>
	</view>
</template>

<script>
import { sendEmailRegisterCode, registerByEmail, loginH5, getUserInfo } from '@/api/user';
const BACK_URL = 'login_back_url';

export default {
	data() {
		return { email: '', captcha: '', password: '', protocol: false, sending: false, submitting: false, countdown: 0, timer: null };
	},
	onUnload() { if (this.timer) clearInterval(this.timer); },
	methods: {
		validEmail() { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.email) && this.email.length <= 100; },
		async sendCode() {
			if (!this.validEmail()) return this.$util.Tips({ title: '请输入正确的邮箱地址' });
			this.sending = true;
			try {
				await sendEmailRegisterCode({ email: this.email });
				this.countdown = 60;
				this.timer = setInterval(() => { if (this.countdown > 0) this.countdown--; else clearInterval(this.timer); }, 1000);
				this.$util.Tips({ title: '如果该邮箱可注册，验证码已发送' });
			} catch (error) { this.$util.Tips({ title: error }); }
			finally { this.sending = false; }
		},
		async submit() {
			if (!this.protocol) return this.$util.Tips({ title: '请先阅读并同意用户协议与隐私协议' });
			if (!this.validEmail()) return this.$util.Tips({ title: '请输入正确的邮箱地址' });
			if (!/^\d{6}$/.test(this.captcha)) return this.$util.Tips({ title: '请输入6位邮箱验证码' });
			if (this.password.length < 8 || this.password.length > 72) return this.$util.Tips({ title: '密码长度须为8到72位' });
			this.submitting = true;
			try {
				await registerByEmail({ email: this.email, captcha: this.captcha, password: this.password, spread: this.$Cache.get('spread') });
				const { data } = await loginH5({ account: this.email, password: this.password, spread: this.$Cache.get('spread'), agent_id: this.$Cache.get('agent_id') || 0 });
				this.$store.commit('LOGIN', { token: data.token, time: data.expires_time - this.$Cache.time() });
				const user = await getUserInfo();
				this.$store.commit('SETUID', user.data.uid);
				const backUrl = this.$Cache.get(BACK_URL) || '/pages/index/index';
				this.$Cache.clear(BACK_URL);
				uni.reLaunch({ url: backUrl });
			} catch (error) { this.$util.Tips({ title: error }); }
			finally { this.submitting = false; }
		}
	}
};
</script>

<style lang="scss" scoped>
.email-auth { padding: 48rpx; color: #222; }
.title { margin: 30rpx 0 48rpx; font-size: 40rpx; font-weight: 600; }
.field { margin: 24rpx 0; padding: 24rpx 18rpx; border-bottom: 1px solid #eee; }
.field input { height: 56rpx; font-size: 28rpx; }
.code-row { display: flex; align-items: center; justify-content: space-between; }
.code-row button { margin: 0; padding: 0 18rpx; font-size: 24rpx; color: #e93323; background: transparent; }
.protocol { margin: 36rpx 0; font-size: 24rpx; color: #666; }
.submit { margin-top: 30rpx; color: white; background: #e93323; border-radius: 44rpx; }
</style>
