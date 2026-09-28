<template>
  <div class="page-account nu-admin-login">
    <div class="nu-admin-orbit" aria-hidden="true"></div>
    <main class="nu-admin-card">
      <header class="nu-admin-brand">
        <div class="nu-admin-wordmark">Nuyoahcc</div>
        <div class="nu-admin-company">杭州苏迪服饰有限公司</div>
      </header>
      <div class="nu-admin-intro">
        <div class="nu-admin-eyebrow">商城管理 <span>ADMINISTRATION</span></div>
        <h1>欢迎回来</h1>
        <p>登录后管理商品与订单</p>
      </div>
      <el-form ref="formInline" :model="formInline" :rules="ruleInline" label-position="top" @keyup.enter="handleSubmit('formInline')">
        <el-form-item prop="username" label="管理员账号">
          <el-input v-model="formInline.username" type="text" prefix-icon="el-icon-user" placeholder="请输入管理员账号" autocomplete="username" size="large" />
        </el-form-item>
        <el-form-item prop="password" label="登录密码">
          <el-input v-model="formInline.password" type="password" prefix-icon="el-icon-lock" placeholder="请输入密码" autocomplete="current-password" size="large" show-password />
        </el-form-item>
        <el-form-item class="nu-admin-submit">
          <el-button type="primary" :loading="loading" size="large" v-db-click @click="handleSubmit('formInline')" class="btn">登录管理后台</el-button>
        </el-form-item>
      </el-form>
      <div class="nu-admin-help"><span>仅供商城管理员使用</span><a href="/">返回商城 <span aria-hidden="true">↗</span></a></div>
    </main>
    <Verify :key="captchaSize.width" @success="success" captchaType="blockPuzzle" :imgSize="captchaSize" ref="verify"></Verify>
    <footer class="nu-admin-footer">
      <div class="nu-admin-signature">NUYOAHCC · HANGZHOU</div>
      <div v-if="copyright" class="nu-admin-copyright">{{ copyright }}</div>
      <div v-else class="nu-admin-copyright">Copyright © 2014-2025 <a href="https://www.crmeb.com" target="_blank" rel="noopener">{{ version }}</a></div>
    </footer>
  </div>
</template>
<script>
import { AccountLogin, loginInfoApi } from '@/api/account';
import { getWorkermanUrl } from '@/api/kefu';
import { setCookies } from '@/libs/util';
import Verify from '@/components/verifition/Verify';
import { PrevLoading } from '@/utils/loading.js';
import { formatFlatteningRoutes, findFirstNonNullChildren } from '@/libs/system';
import { Local } from '@/utils/storage.js';

export default {
  components: {
    Verify,
  },
  data() {
    return {
      fullWidth: document.documentElement.clientWidth,
      loading: false,
      isShow: false,
      imgcode: '',
      formInline: {
        username: '',
        password: '',
      },
      ruleInline: {
        username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
        password: [{ required: true, message: '请输入密码', trigger: 'blur' }],
      },
      login_captcha: 0,
      key: '',
      copyright: '',
      version: '',
      timer: null,
    };
  },
  computed: {
    captchaSize() {
      return { width: String(Math.min(330, Math.max(240, this.fullWidth - 64))) + 'px', height: '155px' };
    },
  },
  created() {
    document.onkeydown = (e) => {
      if (this.$route.name === 'login' && (e.keyCode === 13 || e.which === 13)) {
        this.handleSubmit('formInline');
      }
    };
    window.addEventListener('resize', this.handleResize);
  },
  mounted() {
    this.$nextTick(() => {
      this.handleResize();
      this.swiperData();
    });
  },
  beforeDestroy() {
    window.removeEventListener('resize', this.handleResize);
    document.onkeydown = null;
  },
  methods: {
    swiperData() {
      loginInfoApi()
        .then((res) => {
          const data = res.data || {};
          document.title = 'Nuyoahcc · 商城管理登录';
          localStorage.setItem('ADMIN_TITLE', data.site_name || '');
          this.$store.commit('setAdminTitle', data.site_name);
          this.key = data.key;
          this.copyright = data.copyright;
          this.version = data.version;
          this.login_captcha = data.login_captcha;
        })
        .catch((err) => {
          this.$message.error(err);
        });
    },
    success(params) {
      this.closeModel(params);
    },
    closeModel(params) {
      this.isShow = false;
      this.loading = true;
      AccountLogin({
        account: this.formInline.username,
        pwd: this.formInline.password,
        key: this.key,
        captchaType: 'blockPuzzle',
        captchaVerification: params ? params.captchaVerification : '',
      })
        .then(async (res) => {
          const data = res.data;
          const expires = this.getExpiresTime(data.expires_time);
          setCookies('uuid', data.user_info.id, expires);
          setCookies('token', data.token, expires);
          setCookies('expires_time', data.expires_time, expires);
          Local.set('PERMISSIONS', data.site_func);
          this.$store.commit('userInfo/uniqueAuth', data.unique_auth);
          this.$store.commit('userInfo/userInfo', data.user_info);
          this.$store.commit('menus/setopenMenus', []);
          this.$store.commit('menus/getmenusNav', data.menus);
          this.$store.dispatch('routesList/setRoutesList', data.menus);
          const arr = formatFlatteningRoutes(this.$router.options.routes);
          this.formatTwoStageRoutes(arr);
          this.$store.commit('menus/setOneLvMenus', arr);
          const routes = formatFlatteningRoutes(data.menus);
          this.$store.commit('menus/setOneLvRoute', routes);
          this.$store.commit('userInfo/name', data.user_info.account);
          this.$store.commit('userInfo/avatar', data.user_info.head_pic);
          this.$store.commit('userInfo/access', data.unique_auth);
          this.$store.commit('userInfo/logo', data.logo);
          this.$store.commit('userInfo/logoSmall', data.logo_square);
          this.$store.commit('userInfo/version', data.version);
          this.$store.commit('userInfo/newOrderAudioLink', data.newOrderAudioLink);
          this.login_captcha = 0;
          try {
            if (data.queue === false) {
              this.$notify.warning({
                title: '温馨提示',
                dangerouslyUseHTMLString: true,
                message:
                  '您的【消息队列】未开启，没有开启会导致异步任务无法执行。请尽快执行命令开启！！<a href="https://doc.crmeb.com/single/v54/13667" target="_blank">点击查看开启方法</a>',
                duration: 30000,
              });
            }
            if (data.timer === false) {
              setTimeout(() => {
                this.$notify.warning({
                  title: '温馨提示',
                  dangerouslyUseHTMLString: true,
                  message:
                    '您的【定时任务】未开启，没有开启会导致自动收货、未支付自动取消订单、订单自动好评、拼团到期退款等任务无法正常执行。请尽快执行命令开启！！<a href="https://doc.crmeb.com/single/v54/13667" target="_blank">点击查看开启方法</a>',
                  duration: 30000,
                });
              }, 0);
            }
            this.checkSocket();
          } catch (e) {}
          PrevLoading.start();
          this.$router.push({
            path: data.menus.length ? findFirstNonNullChildren(data.menus).path : this.$routeProStr + '/',
          });
        })
        .catch((res) => {
          const data = res || {};
          this.$message.error(data.msg || '登录失败');
          if (res && res.data) this.login_captcha = res.data.login_captcha;
        })
        .finally(() => {
          setTimeout(() => {
            this.loading = false;
          }, 1000);
        });
    },
    formatTwoStageRoutes(arr) {
      if (!arr.length) return false;
      const cacheList = [];
      arr.forEach((v) => {
        if (v && v.meta && v.meta.keepAlive) {
          cacheList.push(v.name);
        }
      });
      if (cacheList.length) {
        this.$store.dispatch('keepAliveNames/setCacheKeepAlive', cacheList);
      }
    },
    checkSocket() {
      getWorkermanUrl().then((res) => {
        const url = res.data.admin;
        let isNotice = false;
        const socket = new window.WebSocket(url);
        socket.onopen = () => {
          isNotice = true;
          socket.close();
        };
        socket.onerror = socket.onclose = () => {
          if (!isNotice) {
            isNotice = true;
            this.$notify.warning({
              title: '温馨提示',
              message:
                '您的【长连接】未开启，没有开启会导致系统默认客服无法使用,后台订单通知无法收到。请尽快执行命令开启！！<a href="https://doc.crmeb.com/single/v54/13667" target="_blank">点击查看开启方法</a>',
              dangerouslyUseHTMLString: true,
              duration: 30000,
            });
          }
        };
      });
    },
    getExpiresTime(expiresTime) {
      const nowTimeNum = Math.round(Date.now() / 1000);
      const expiresTimeNum = expiresTime - nowTimeNum;
      return parseFloat(expiresTimeNum / 60 / 60 / 24);
    },
    closefail() {
      this.$message.error('校验错误');
    },
    handleResize() {
      this.fullWidth = document.documentElement.clientWidth;

    },
    handleSubmit(name) {
      this.$refs[name].validate((valid) => {
        if (valid) {
          if (this.login_captcha === 1) {
            this.$refs.verify.show();
          } else {
            this.closeModel();
          }
        }
      });
    },
  },
};
</script>
<style lang="scss" scoped>
.nu-admin-login {
  --paper:#f8f6f2; --ink:#1c1d1a; --muted:#77776e; --line:#d6d3cc;
  --prev-color-primary:var(--ink); --prev-color-primary-light-3:#353630; --prev-color-primary-light-7:#77776e;
  position:relative;display:flex;flex-direction:column;align-items:center;
  min-height:100vh;min-height:100svh;padding:68px 32px 24px;box-sizing:border-box;
  overflow-x:hidden;background:var(--paper);color:var(--ink);
  font-family:'Helvetica Neue',Arial,'PingFang SC','Microsoft YaHei',sans-serif;
}
.nu-admin-card { position:relative;z-index:1;width:100%;max-width:420px;margin:auto; }
.nu-admin-wordmark { font-family:Didot,'Bodoni MT','Times New Roman',serif;font-size:58px;font-weight:400;line-height:1.1;letter-spacing:-3px; }
.nu-admin-company { margin-top:14px;color:var(--muted);font-size:11px;letter-spacing:2px;line-height:1.8; }
.nu-admin-intro { margin:52px 0 28px; }
.nu-admin-eyebrow { display:flex;align-items:center;gap:14px;color:var(--muted);font-size:11px;letter-spacing:1px; }
.nu-admin-eyebrow span { font-size:9px;letter-spacing:2px; }
.nu-admin-intro h1 { margin:20px 0 8px;font-size:23px;line-height:1.5;font-weight:500;letter-spacing:1px; }
.nu-admin-intro p { margin:0;color:var(--muted);font-size:13px;line-height:1.8; }
.nu-admin-card ::v-deep .el-form-item { margin-bottom:24px; }
.nu-admin-card ::v-deep .el-form-item__label { float:none;padding:0;line-height:24px;font-size:12px;font-weight:400;color:var(--muted); }
.nu-admin-card ::v-deep .el-input__inner {
  height:48px;line-height:48px;padding-left:29px;background:transparent;color:var(--ink);
  border:0;border-bottom:1px solid var(--line);border-radius:0;box-shadow:none;font-size:14px;
}
.nu-admin-card ::v-deep .el-input__inner:hover,
.nu-admin-card ::v-deep .el-input__inner:focus { border-bottom-color:var(--ink); }
.nu-admin-card ::v-deep .el-input__inner::placeholder { color:#a4a299; }
.nu-admin-card ::v-deep .el-input__prefix { left:0;color:var(--muted); }
.nu-admin-card ::v-deep .el-input__suffix { right:0;color:var(--muted); }
.nu-admin-card ::v-deep .el-input__icon { line-height:48px; }
.nu-admin-card ::v-deep input:-webkit-autofill { -webkit-box-shadow:0 0 0 1000px var(--paper) inset;-webkit-text-fill-color:var(--ink); }
.nu-admin-card ::v-deep .el-form-item.is-error .el-input__inner { border-bottom-color:#ad514a; }
.nu-admin-card ::v-deep .el-form-item__error { color:#ad514a;font-size:12px;padding-top:6px; }
.nu-admin-card .nu-admin-submit { margin:32px 0 18px; }
.nu-admin-card .btn { width:100%;height:48px;border:1px solid var(--ink);border-radius:0;background:var(--ink);color:#fff;font-size:14px;letter-spacing:2px; }
.nu-admin-card .btn:hover,.nu-admin-card .btn:focus { background:#353630;border-color:#353630; }
.nu-admin-help { display:flex;align-items:center;justify-content:space-between;gap:12px;font-size:11px;line-height:1.8;color:var(--muted); }
.nu-admin-help a { color:var(--ink);text-decoration:none;border-bottom:1px solid var(--line); }
.nu-admin-help a:focus-visible { outline:1px solid var(--ink);outline-offset:5px; }
.nu-admin-orbit { position:absolute;width:240px;height:240px;border:1px solid #d7c6be;border-radius:50%;top:7%;right:-120px;pointer-events:none; }
.nu-admin-orbit::after { content:'';position:absolute;inset:24px;border:1px solid #e0d2ca;border-radius:50%; }
.nu-admin-footer { position:relative;z-index:1;width:100%;margin-top:56px;text-align:center;color:#929188;line-height:1.8; }
.nu-admin-signature { font-size:10px;letter-spacing:2px; }
.nu-admin-copyright { margin-top:8px;font-size:10px; }
.nu-admin-copyright a { color:inherit;text-decoration:none; }
@media(max-width:600px) {
  .nu-admin-login { padding:64px 28px 22px; }
  .nu-admin-card { max-width:420px; }
  .nu-admin-wordmark { font-size:52px; }
  .nu-admin-intro { margin-top:44px; }
  .nu-admin-orbit { width:190px;height:190px;top:10%;right:-118px; }
  .nu-admin-card ::v-deep .el-input__inner { font-size:16px; }
  .nu-admin-footer { margin-top:56px; }
}
</style>

