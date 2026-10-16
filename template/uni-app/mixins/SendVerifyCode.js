import { smsDeadline } from '@/utils/smsSend.js';
export default {
 data() { return { disabled: false, text: this.$t('获取验证码'), runTime: undefined, smsUntil: 0, captchaType: 'clickWord' }; },
 methods: {
  smsPhone() { return String(this.phone || this.account || ''); },
  updateSmsCountdown() {
   const n = Math.max(0, Math.ceil((this.smsUntil - Date.now()) / 1000));
   this.disabled = n > 0;
   this.text = n > 0 ? this.$t('剩余') + n + 's' : this.$t('重新获取');
   if (!n) clearInterval(this.runTime);
  },
  sendCode() {
   clearInterval(this.runTime);
   this.smsUntil = smsDeadline(this.smsPhone()) || Date.now() + 60000;
   this.updateSmsCountdown();
   if (this.disabled) this.runTime = setInterval(() => this.updateSmsCountdown(), 250);
  },
  resumeSmsCountdown() {
   clearInterval(this.runTime);
   this.smsUntil = smsDeadline(this.smsPhone());
   this.updateSmsCountdown();
   if (this.disabled) this.runTime = setInterval(() => this.updateSmsCountdown(), 250);
  }
 },
 watch: { phone() { this.resumeSmsCountdown(); }, account() { this.resumeSmsCountdown(); } },
 created() {
  this.smsDeadlineListener = phone => { if (phone === this.smsPhone()) this.resumeSmsCountdown(); };
  uni.$on('sms:deadline', this.smsDeadlineListener);
 },
 mounted() { this.resumeSmsCountdown(); },
 onShow() { this.resumeSmsCountdown(); },
 onHide() { clearInterval(this.runTime); },
 onUnload() { clearInterval(this.runTime); },
 beforeDestroy() { clearInterval(this.runTime); uni.$off('sms:deadline', this.smsDeadlineListener); }
};
