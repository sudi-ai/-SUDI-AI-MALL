const {defineConfig} = require('@playwright/test');
module.exports = defineConfig({
  testDir: '.', testMatch: 'storefront.spec.cjs', workers: 1, retries: 0,
  timeout: 60000, reporter: 'list',
  use: {baseURL: 'http://127.0.0.1:8000', viewport: {width: 390, height: 844},
    screenshot: 'only-on-failure', trace: 'off'},
});
