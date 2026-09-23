const {test, expect} = require('@playwright/test');
const fs = require('fs');
const fixture = JSON.parse(fs.readFileSync('/tmp/sudi-browser-fixture.json', 'utf8'));

test.beforeEach(async ({page}) => {
  await page.addInitScript(({token, uid}) => {
    localStorage.setItem('LOGIN_STATUS_TOKEN', token);
    localStorage.setItem('UID', JSON.stringify({type: 'number', data: uid}));
  }, fixture);
});

test('fresh H5 home displays the ordinary product and opens detail', async ({page}) => {
  await page.goto('/');
  await expect(page.getByText('新品上架').first()).toBeVisible({timeout: 30000});
  const product = page.getByText(fixture.productName, {exact: false}).first();
  await expect(product).toBeVisible();
  await expect(page.getByText('AI 购物', {exact: true}).first()).toBeVisible();
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
  expect(await page.locator('img').evaluateAll(imgs => imgs.some(i => i.src.includes('test.jpg') && i.complete && i.naturalWidth > 0))).toBeTruthy();
  await page.screenshot({path: 'test-results/detail.png', fullPage: true});
  await page.getByText('AI 尺码', {exact: true}).click();
  await expect(page.getByPlaceholder('身高 cm')).toBeVisible();
  await page.getByPlaceholder('身高 cm').fill('165');
  await page.getByPlaceholder('体重 kg').fill('55');
  await page.getByText('获取建议', {exact: true}).click();
  await expect(page.getByText(/商品缺少尺码表|没有可直接匹配|暂未提供可计算/).first()).toBeVisible();
  await page.screenshot({path: 'test-results/size.png', fullPage: true});
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
  await page.goto('/pages/admin/goods/addGoods');
  await expect(page.getByPlaceholder('请填写商品名称')).toBeVisible({timeout: 30000});
  await page.getByPlaceholder('请填写商品名称').fill('苏迪 CI 女装');
  await page.getByPlaceholder('颜色，用逗号分开，例如：黑色,灰色').fill('黑色,灰色');
  await page.getByPlaceholder('尺码，用逗号分开，例如：XS,S,M,L').fill('S,M,L');
  await expect(page.locator('.sudi-sku-row')).toHaveCount(6);
  await expect(page.getByText('上传图片', {exact: true})).toBeVisible();
  await page.screenshot({path: 'test-results/merchant-product.png', fullPage: true});
});
