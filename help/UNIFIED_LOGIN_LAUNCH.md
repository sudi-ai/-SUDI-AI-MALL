# 统一登录与真实接入

微信、手机号、邮箱都指向 `eb_user.uid`，订单、余额、积分不随登录方式复制或迁移。

## 用户路径

- 邮箱注册后，在个人资料验证绑定手机号；之后手机号密码登录和短信登录使用同一 UID。微信服务端授权返回已验证的相同手机号时，关联到该 UID。
- 已有手机或微信账号，在个人资料「登录邮箱」验证绑定邮箱。绑定时设置的密码同时用于邮箱和手机号密码登录。
- 微信再次授权按 openid/unionid 找回同一 UID，包括首次返回 unionid 的情况。
- 不同 UID 的微信和手机号碰撞会拒绝操作；历史重复账号需核验归属后另行处理，不能自动合并余额和订单。
- 手机确认步骤始终验证短信；验证码注册与绑定邮箱分用途、分当前用户。数据库唯一约束阻止重复手机号/openid归属。
- 旧远程免验签登录与仅上传 openid/unionid 的原生 APP 登录已关闭。微信公众号及小程序保留服务端 OAuth 流程。

## 真实服务尚未开通

2026-09-26 用户确认微信商户、公众号/小程序、短信与发信邮箱尚未开通。CI 的 SMTP 和 OAuth 身份为隔离测试替身，不证明真实收信、微信授权或扣款成功。

1. 微信内 H5：申请公众号服务号、商户号和 JSAPI 权限，绑定 APPID；配置网页授权域名及支付授权目录。小程序另需小程序 APPID，与公众号统一身份时需同一开放平台的 unionid 配置。
   官方接入准备：https://pay.wechatpay.cn/doc/v3/merchant/4015423216
2. 短信：现有代码支持 CRMEB、阿里云、腾讯云；选择一种，完成账号、签名及验证码模板审核。腾讯云驱动为 `sms_type=2`，配置 `tencent_sms_app_id`、`tencent_sms_secret_id`、`tencent_sms_secret_key`、`tencent_sms_sign_name`、`tencent_sms_region` 以及验证码通知对应的模板 ID。
   官方说明：https://cloud.tencent.com/document/product/382/13444
3. 邮箱：开通支持 TLS 的 SMTP 发信服务，在商城私有 `.env` 配置 `[EMAIL]`。具体字段见 `EMAIL_AUTH_SETUP.md`，凭据不提交仓库。
4. 微信支付：填写相应 APPID/AppSecret、商户号、V3 API 密钥、商户证书序列号与私钥、微信支付公钥 ID/公钥；商品支付回调地址由商城 `site_url` 生成。所有地址使用 `mall.sudix.cn`。

## 上线顺序

1. 完整 CI 通过后，备份商城数据库、当前发布目录及上传文件，验证备份可恢复；记录商品数、订单数、用户数与上传文件校验和。
2. 检查非空 phone 和同类型 openid 是否重复；如重复，停止该升级，不删除、不自动合并。
3. 先执行 `sudi_email_auth_migration.sql`，再执行 `sudi_identity_migration.sql`。仅增量扩展邮箱、密码长度和身份唯一约束，两脚本可重复执行；唯一约束如冲突必须处理归属后重试。
4. 部署 CI 验证的代码和 H5，配置服务，再用本人测试手机号/邮箱/微信验收同一 UID、订单归属及收信。最后由用户完成一笔明确金额的真实付款，核对回调、入账、库存和退款。
5. 仅操作商城资源，不重建或改动 `www.sudix.cn` 生图服务。当前缺少外部服务，不自动发布。

## 测试证据

`identity_service_integration.php` 在 GitHub Actions 的临时 MySQL/Redis 里调用实际邮件发送、注册、登录、绑定接口；SMTP 消息由本地测试服务器接收，微信身份由服务器服务层 fixture 模拟。

日志在 CI 的 `sudi-regression-logs` 制品：`sudi-identity-integration.log`、`sudi-service-integration.log`；浏览器截图位于 `sudi-browser-evidence`。不能将这些记录描述为真实第三方接入验收。
