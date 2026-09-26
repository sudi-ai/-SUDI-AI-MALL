const {test, expect} = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const fixture = JSON.parse(fs.readFileSync('/tmp/sudi-browser-fixture.json', 'utf8'));
// uni-app draws placeholders in sibling views, not native placeholder attributes.
const field = (page, label) => page.locator('uni-input,uni-textarea').filter({hasText: label}).locator('input,textarea').first();

test.beforeEach(async ({page}) => {
  await page.addInitScript(({token, uid}) => {
    localStorage.setItem('LOGIN_STATUS_TOKEN', token);
    localStorage.setItem('UID', JSON.stringify({type: 'number', data: uid}));
    localStorage.setItem('UNI-APP-CRMEB:TAG', JSON.stringify({type: 'object', data: [
      {key: 'LOGIN_STATUS_TOKEN', expire: 0}, {key: 'UID', expire: 0},
    ]}));
  }, fixture);
});

test('fresh H5 home displays the ordinary product and opens detail', async ({page}) => {
  await page.goto('/');
  await expect(page.getByText('新品上架').first()).toBeVisible({timeout: 30000});
  const product = page.getByText(fixture.productName, {exact: false}).first();
  await expect(product).toBeVisible();
  await expect(page.getByText('AI 购物', {exact: true}).first()).toBeVisible();
  await expect(page.locator('body')).not.toContainText('includes(item.name)');
  await expect(page.locator('uni-page-body')).not.toContainText(/限时秒杀|拼团活动|砍价中心|积分商城|立即签到|抽奖活动|九阳/);
  await expect(page.locator('.hotspot')).toHaveCount(0, {timeout: 30000});
  await page.screenshot({path: 'test-results/home.png', fullPage: true});
  await product.click();
  await expect(page).toHaveURL(/goods_details/);
  await expect(page.getByText('苏迪 AI 购前助手')).toBeVisible();
});

test('mobile SMS login creates an account and returns to shopping', async ({browser}) => {
  // A disposable, seeded OTP tests the real login API, not Aliyun SMS delivery.
  const sms = JSON.parse(fs.readFileSync('/tmp/sudi-sms-browser.json', 'utf8'));
  const context = await browser.newContext({viewport: {width: 390, height: 844}, isMobile: true, hasTouch: true});
  const page = await context.newPage();
  try {
    await page.addInitScript(back => {
      localStorage.setItem('login_back_url', JSON.stringify({type: 'string', data: back}));
      localStorage.setItem('UNI-APP-CRMEB:TAG', JSON.stringify({type: 'object', data: [{key: 'login_back_url', expire: 0}]}));
    }, '/pages/goods_details/index?id=' + fixture.productId);
    await page.goto('http://127.0.0.1:8000/pages/users/login/index');
    await expect(page.getByText('未注册手机号验证通过后将自动创建账号', {exact: true})).toBeVisible();
    await expect(page.locator('input[type=password]')).toHaveCount(0);
    await expect(page.getByText('邮箱注册', {exact: true})).toHaveCount(0);
    await field(page, '输入手机号码').fill(sms.phone);
    await field(page, '填写验证码').fill(sms.code);
    await expect(field(page, '填写验证码')).toHaveAttribute('autocomplete', 'one-time-code');
    await page.locator('.protocol uni-checkbox').click();
    await page.screenshot({path: 'test-results/mobile-code-login.png', fullPage: true});
    const login = page.waitForResponse(r => r.url().includes('/api/login/mobile'));
    await page.getByText('验证并登录', {exact: true}).click();
    expect((await (await login).json()).status).toBe(200);
    await expect(page).toHaveURL(/goods_details/);
    await expect(page.getByText(fixture.productName).first()).toBeVisible();
    const replay = await context.request.post('http://127.0.0.1:8000/api/login/mobile', {data: {phone: sms.phone, captcha: sms.code}});
    expect((await replay.json()).status).not.toBe(200);
    await page.screenshot({path: 'test-results/mobile-login-shopping.png', fullPage: true});
  } finally { await context.close(); }
});

test('buyer signs in through the password login page', async ({browser}) => {
  const context = await browser.newContext({viewport: {width: 390, height: 844}});
  const page = await context.newPage();
  try {
    await page.goto('http://127.0.0.1:8000/pages/users/login/index');
    await page.getByText('账号登录', {exact: true}).click();
    await field(page, '手机号、邮箱或账号').fill('sudibuyer');
    await field(page, '填写登录密码').fill('SudiCiOnly42');
    await page.locator('.protocol uni-checkbox').click();
    const login = page.waitForResponse(r => /\/api\/login(?:\?|$)/.test(r.url()));
    await page.locator('.logon').click();
    expect((await (await login).json()).status).toBe(200);
    await expect(page).not.toHaveURL(/\/users\/login\//);
    await expect(page.getByText('新品上架').first()).toBeVisible();
    await page.screenshot({path: 'test-results/buyer-login.png', fullPage: true});
  } finally { await context.close(); }
});

test('detail renders product images, options, and all three AI entries', async ({page}) => {
  await page.goto('/pages/goods_details/index?id=' + fixture.productId);
  await expect(page.getByText(fixture.productName).first()).toBeVisible({timeout: 30000});
  await expect(page.getByText('AI 尺码', {exact: true})).toBeVisible();
  await expect(page.getByText('AI 搭配', {exact: true})).toBeVisible();
  await expect(page.getByText('问 AI 客服', {exact: true})).toBeVisible();
  await expect(page.locator('body')).not.toContainText('includes(item.name)');
  expect(await page.locator('img').evaluateAll(imgs => imgs.some(i => i.src.includes('test.jpg') && i.complete && i.naturalWidth > 0))).toBeTruthy();
  await page.screenshot({path: 'test-results/detail.png', fullPage: true});
  await page.getByText('AI 尺码', {exact: true}).click();
  await expect(field(page, '身高 cm')).toBeVisible();
  await field(page, '身高 cm').fill('165');
  await field(page, '体重 kg').fill('55');
  await page.getByText('获取建议', {exact: true}).click();
  await expect(page.getByText(/商品缺少尺码表|没有可直接匹配|暂未提供可计算/).first()).toBeVisible();
  await page.screenshot({path: 'test-results/size.png', fullPage: true});
  await page.goto('/pages/goods_details/index?id=' + fixture.productId);
  await page.getByText('AI 搭配', {exact: true}).click();
  await expect(page.getByText('生成搭配', {exact: true})).toBeVisible();
  const outfit = page.waitForResponse(r => r.url().includes('/api/sudi/ai/outfit'));
  await page.getByText('生成搭配', {exact: true}).click();
  expect((await (await outfit).json()).status).toBe(200);
  await page.goto('/pages/goods_details/index?id=' + fixture.productId);
  await page.getByText('问 AI 客服', {exact: true}).click();
  await field(page, '例如：什么时候发货？尺码怎么选？').fill('我想申请退款');
  await page.getByText('发送', {exact: true}).click();
  await expect(page.getByText(/商城正式流程/)).toBeVisible();
});

test('existing buyer can reach email binding without creating a second account', async ({page}) => {
  await page.goto('/pages/users/user_info/index');
  await expect(page.getByText('登录邮箱', {exact: true})).toBeVisible({timeout: 30000});
  await page.getByText('点击绑定邮箱', {exact: false}).click();
  await expect(page).toHaveURL(/email_auth\/index\?mode=bind/);
  await expect(page.getByText('验证并绑定', {exact: true})).toBeVisible();
  await expect(field(page, '请输入邮箱地址')).toBeVisible();
  await expect(page.getByText('注册并登录', {exact: true})).toHaveCount(0);
  await page.screenshot({path: 'test-results/email-binding.png', fullPage: true});
});

test('category and cart pages load without a blank page', async ({page}) => {
  const categories = await (await page.request.get('/api/category')).json();
  expect(categories.data.find(category => category.id === 1).cate_name).toBe('手机数码');
  await page.goto('/pages/goods_cate/goods_cate');
  await expect(page.locator('uni-page-body')).toContainText(/分类|女装|服饰|暂无/, {timeout: 30000});
  await expect(page.getByText('手机数码', {exact: true}).first()).toBeVisible();
  await page.screenshot({path: 'test-results/category.png', fullPage: true});
  await page.getByText('全部商品', {exact: true}).first().click();
  await expect(page.getByText(fixture.productName).first()).toBeVisible({timeout: 30000});
  await page.getByText(fixture.productName).first().click();
  await expect(page).toHaveURL(/goods_details/);
  await page.goto('/pages/order_addcart/order_addcart');
  await expect(page.locator('uni-page-body')).toContainText(/购物车|去逛逛|商品/, {timeout: 30000});
  await page.screenshot({path: 'test-results/cart.png', fullPage: true});
});

test('merchant product form previews two colors by three sizes', async ({page}) => {
  test.setTimeout(120000);
  const title = '苏迪 CI 女装浏览器回归';
  await page.goto('/pages/admin/goods/addGoods');
  await expect(field(page, '请填写商品名称')).toBeVisible({timeout: 30000});
  await field(page, '请填写商品名称').fill(title);
  await field(page, '颜色，用逗号分开，例如：黑色,灰色').fill('黑色,灰色');
  await field(page, '尺码，用逗号分开，例如：XS,S,M,L').fill('S,M,L');
  await expect(page.locator('.sudi-sku-row')).toHaveCount(6);
  await expect(page.getByText('上传图片', {exact: true}).first()).toBeVisible();
  const chooserPromise = page.waitForEvent('filechooser');
  await page.getByText('上传图片', {exact: true}).first().click();
  await (await chooserPromise).setFiles([path.resolve(__dirname, '../../crmeb/public/test.jpg'), path.resolve(__dirname, '../../crmeb/public/test2.jpg')]);
  await expect(page.locator('.grid-column-4 img')).toHaveCount(2, {timeout: 30000});
  await page.getByText('请选择分类', {exact: true}).click();
  await page.locator('.classify uni-checkbox').first().click();
  await page.locator('.classify').getByText('确定', {exact: true}).click();
  for (let i = 0; i < 6; i++) {
    const row = page.locator('.sudi-sku-row').nth(i);
    await row.locator('input').nth(0).fill(String(199 + i));
    await row.locator('input').nth(1).fill(String(i + 1));
  }
  await page.screenshot({path: 'test-results/merchant-product.png', fullPage: true});
  const saved = page.waitForResponse(r => r.url().includes('/api/admin/manage/product/create'));
  await page.getByText('保存草稿', {exact: true}).click();
  expect((await (await saved).json()).status).toBe(200);
  await expect(page).toHaveURL(/\/pages\/admin\/goods\/index/);
  const hidden = await (await page.request.get('/api/products', {params: {keyword: title}})).json();
  expect(hidden.data).toHaveLength(0);
  const row = page.locator('.list .item').filter({hasText: title}).first();
  await expect(row).toBeVisible();
  const published = page.waitForResponse(r => r.url().includes('/api/admin/manage/product/set_show'));
  await row.getByText('上架', {exact: true}).click();
  expect((await (await published).json()).status).toBe(200);
  await page.goto('/');
  await expect(page.getByText(title).first()).toBeVisible({timeout: 30000});
  await page.screenshot({path: 'test-results/published-product.png', fullPage: true});
});

test('selected SKU goes from detail to cart and checkout with the saved address', async ({page}) => {
  await page.goto('/pages/goods_details/index?id=' + fixture.productId);
  await page.getByText('加入购物车', {exact: true}).click();
  const options = page.locator('.product-window.on');
  await expect(options).toBeVisible();
  await options.locator('.itemn').getByText('灰', {exact: true}).click();
  await options.locator('.itemn').getByText('L', {exact: true}).click();
  await expect(options.locator('.stock').first()).toContainText('1');
  const added = page.waitForResponse(r => r.url().includes('/api/cart/add'));
  await page.getByText('加入购物车', {exact: true}).click();
  expect((await (await added).json()).status).toBe(200);
  await page.goto('/pages/order_addcart/order_addcart');
  const item = page.locator('.list .item').filter({hasText: fixture.productName}).first();
  await expect(item).toContainText('灰,L');
  await expect(item.locator('.money')).toContainText('204');
  if (!await item.locator('.uni-checkbox-input-checked').count()) await item.locator('uni-checkbox').click();
  await expect(page.getByText('全选(1)', {exact: true})).toBeVisible();
  const confirmation = page.waitForResponse(r => r.url().includes('/api/order/confirm'));
  await page.getByText('立即下单', {exact: true}).click();
  expect((await (await confirmation).json()).status).toBe(200);
  await expect(page).toHaveURL(/order_confirm/);
  await expect(page.locator('.addressCon')).toContainText('CI test only');
  await expect(page.getByText('提交订单', {exact: true})).toBeVisible();
  await page.screenshot({path: 'test-results/checkout.png', fullPage: true});
});
