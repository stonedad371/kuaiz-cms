const { test, expect } = require('@playwright/test');

const sites = {
  short: 'http://127.0.0.1:41731',
  long: 'http://127.0.0.1:41732',
  complex: 'http://127.0.0.1:41733',
  empty: 'http://127.0.0.1:41734',
  rtl: 'http://127.0.0.1:41735',
};

async function openChecked(page, url, expectedStatus = 200) {
  const errors = [];
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(message.text());
  });
  page.on('pageerror', (error) => errors.push(error.message));
  const response = await page.goto(url, { waitUntil: 'networkidle' });
  expect(response && response.status()).toBe(expectedStatus);
  await expect(page.locator('html')).toBeVisible();
  const layout = await page.evaluate(() => ({
    documentWidth: document.documentElement.scrollWidth,
    viewportWidth: document.documentElement.clientWidth,
    bodyWidth: document.body.getBoundingClientRect().width,
    mainWidth: document.querySelector('main')?.getBoundingClientRect().width || 0,
  }));
  expect(layout.documentWidth).toBeLessThanOrEqual(layout.viewportWidth + 1);
  expect(layout.bodyWidth).toBeLessThanOrEqual(layout.viewportWidth + 1);
  expect(layout.mainWidth).toBeGreaterThan(0);
  if (expectedStatus < 400) expect(errors).toEqual([]);
}

test('short content covers home, collection, and detail layouts', async ({ page }) => {
  await openChecked(page, `${sites.short}/`);
  await expect(page.getByText('杭州轻量办公设备服务')).toBeVisible();
  await openChecked(page, `${sites.short}/?page=directory`);
  await expect(page.getByRole('link', { name: '杭州轻量办公设备服务' })).toBeVisible();
  await openChecked(page, `${sites.short}/?page=directory/short-entry`);
  await expect(page.getByRole('heading', { name: '杭州轻量办公设备服务' })).toBeVisible();
});

test('long content paginates and wraps without horizontal overflow', async ({ page }) => {
  await openChecked(page, `${sites.long}/?page=directory`);
  await expect(page.getByText('第 1 / 2 页')).toBeVisible();
  await expect(page.getByText('一个很长但仍然必须在窄屏幕里完整换行且不能撑破页面边界的服务项目标题')).toBeVisible();
  await openChecked(page, `${sites.long}/?page=directory&p=2`);
  await expect(page.getByText('第 2 / 2 页')).toBeVisible();
  await openChecked(page, `${sites.long}/?page=directory/long-entry-35`);
  const heading = page.getByRole('heading', { level: 1 });
  await expect(heading).toBeVisible();
  const box = await heading.boundingBox();
  expect(box && box.width).toBeLessThanOrEqual((await page.evaluate(() => innerWidth)) - 24);
});

test('complex content remains text and never executes injected markup', async ({ page }) => {
  let dialogs = 0;
  page.on('dialog', async (dialog) => {
    dialogs += 1;
    await dialog.dismiss();
  });
  await openChecked(page, `${sites.complex}/?page=directory/complex-entry`);
  await expect(page.getByText('<script>alert("never")</script>', { exact: false })).toBeVisible();
  expect(await page.locator('main script').count()).toBe(0);
  expect(dialogs).toBe(0);
});

test('empty site keeps useful navigation and hides empty featured content', async ({ page }) => {
  await openChecked(page, `${sites.empty}/`);
  await expect(page.getByRole('heading', { name: '快智浏览器验收站' })).toBeVisible();
  await expect(page.getByText('精选内容')).toHaveCount(0);
  await openChecked(page, `${sites.empty}/?page=directory`);
  await expect(page.getByText('暂时还没有可展示的内容。')).toBeVisible();
});

test('RTL seed declares direction and keeps Arabic content inside the viewport', async ({ page }) => {
  await openChecked(page, `${sites.rtl}/`);
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  await expect(page.getByRole('heading', { name: 'دليل الخدمات المحلية' })).toBeVisible();
  await openChecked(page, `${sites.rtl}/?page=directory/arabic-consulting`);
  await expect(page.locator('html')).toHaveAttribute('lang', 'ar-SA');
  await expect(page.getByRole('heading', { name: 'استشارات الأعمال المحلية' })).toBeVisible();
  const direction = await page.locator('body').evaluate((element) => getComputedStyle(element).direction);
  expect(direction).toBe('rtl');
});

test('local admin exposes account, member, backup, search, and pagination screens', async ({ page }) => {
  await openChecked(page, `${sites.long}/admin/`, 401);
  await page.getByLabel('登录名或邮箱').fill('browser-owner@example.com');
  await page.getByLabel('密码').fill('Browser password 2026!');
  await page.getByRole('button', { name: '登录' }).click();
  await page.waitForURL(/\/admin\/$/);
  await expect(page.getByText('第 1 / 2 页')).toBeVisible();
  await page.getByPlaceholder('搜索标题、内容或网址路径').fill('long-entry-35');
  await page.getByRole('button', { name: '搜索' }).click();
  await expect(page.getByText('内容（1）')).toBeVisible();
  for (const [path, heading] of [
    ['/admin/account/', '修改登录密码'],
    ['/admin/users/', '管理后台成员'],
    ['/admin/backups/', '网站备份'],
  ]) {
    await openChecked(page, `${sites.long}${path}`);
    await expect(page.getByRole('heading', { name: heading })).toBeVisible();
  }
});
