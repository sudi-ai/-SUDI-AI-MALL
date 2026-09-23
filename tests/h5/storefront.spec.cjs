const {test, expect} = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const fixture = JSON.parse(fs.readFileSync('/tmp/sudi-browser-fixture.json', 'utf8'));

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
  await page.screenshot({path: 'test-results/home.png', fullPage: true});
  await product.click();
  await expect(page).toHaveURL(/goods_details/);
  await expect(page.getByText('苏迪 AI 购前助手')).toBeVisible();
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
  await expect(page.getByPlaceholder('身高 cm')).toBeVisible();
  await page.getByPlaceholder('身高 cm').fill('165');
  await page.getByPlaceholder('体重 kg').fill('55');
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
  await page.getByPlaceholder('例如：什么时候发货？尺码怎么选？').fill('我想申请退款');
  await page.getByText('发送', {exact: true}).click();
  await expect(page.getByText(/商城正式流程/)).toBeVisible();
});

test('category and cart pages load without a blank page', async ({page}) => {
  await page.goto('/pages/goods_cate/goods_cate');
  await expect(page.locator('uni-page-body')).toContainText(/分类|女装|服饰|暂无/, {timeout: 30000});
  await page.screenshot({path: 'test-results/category.png', fullPage: true});
  await page.goto('/pages/order_addcart/order_addcart');
  await expect(page.locator('uni-page-body')).toContainText(/购物车|去逛逛|商品/, {timeout: 30000});
  await page.screenshot({path: 'test-results/cart.png', fullPage: true});
});

test('merchant product form previews two colors by three sizes', async ({page}) => {
  test.setTimeout(120000);
  const title = '苏迪 CI 女装浏览器回归';
  await page.goto('/pages/admin/goods/addGoods');
  await expect(page.getByPlaceholder('请填写商品名称')).toBeVisible({timeout: 30000});
  await page.getByPlaceholder('请填写商品名称').fill(title);
  await page.getByPlaceholder('颜色，用逗号分开，例如：黑色,灰色').fill('黑色,灰色');
  await page.getByPlaceholder('尺码，用逗号分开，例如：XS,S,M,L').fill('S,M,L');
  await expect(page.locator('.sudi-sku-row')).toHaveCount(6);
  await expect(page.getByText('上传图片', {exact: true})).toBeVisible();
  const chooserPromise = page.waitForEvent('filechooser');
  await page.getByText('上传图片', {exact: true}).click();
  await (await chooserPromise).setFiles([path.resolve(__dirname, '../../crmeb/public/test.jpg'), path.resolve(__dirname, '../../crmeb/public/test2.jpg')]);
  await expect(page.locator('.grid-column-4 img')).toHaveCount(2, {timeout: 30000});
  await page.getByText('请选择分类', {exact: true}).click();
  await page.locator('.classify uni-checkbox').first().click();
  await page.locator('.classify').getByText('确定', {exact: true}).click();
  for (let i = 0; i < 6; i++) {
    const row = page.locator('.sudi-sku-row').nth(i);
    await row.getByPlaceholder('售价', {exact: true}).fill(String(199 + i));
    await row.getByPlaceholder('库存', {exact: true}).fill(String(i + 1));
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
