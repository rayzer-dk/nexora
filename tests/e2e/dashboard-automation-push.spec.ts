import { createHmac } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import { expectNoHorizontalOverflow, expectNoServerError, openProductTab } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

function appSecret(): string {
  if (existsSync('.env.local')) {
    const match = /^APP_SECRET=(.+)$/m.exec(readFileSync('.env.local', 'utf8'));
    if (match) return match[1].trim().replace(/^['"]|['"]$/g, '');
  }
  return process.env.APP_SECRET ?? '';
}
const SECRET = appSecret();
const ALPHABET = 'ACDEFHJKLMNPRTUVWXY34679';
function builtinAnswer(token: string): string {
  const raw = createHmac('sha256', SECRET).update(`code|${token.split('.')[0]}`).digest();
  return Array.from({ length: 5 }, (_, i) => ALPHABET[raw[i] % ALPHABET.length]).join('');
}

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')), page.locator('button[type="submit"]').click()]);
}

test.beforeEach(async ({}, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared CI data once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');
});

test('manual order creates a real order and the dashboard reports it', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/orders/manual', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await page.locator('input[name="name"]').fill('Телефонний Клієнт');
  await page.locator('input[name="phone"]').fill('+380501112233');
  await page.locator('input[name="sku[]"]').first().fill('DEMO-LAP-APP-APP-078');
  await page.locator('input[name="qty[]"]').first().fill('2');
  await page.locator('input[name="delivery"]').fill('Київ, Нова пошта №1');
  await Promise.all([page.waitForURL(/\/admin\/orders\/[0-9a-f-]{36}/), page.locator('main form button[type="submit"]').last().click()]);
  await expectNoServerError(page);
  await expect(page.locator('body')).toContainText('Телефонний Клієнт');

  // unknown SKU never creates a phantom order
  await page.goto('/admin/orders/manual', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="name"]').fill('X');
  await page.locator('input[name="phone"]').fill('+380501112233');
  await page.locator('input[name="sku[]"]').first().fill('NO-SUCH-SKU');
  await page.locator('main form button[type="submit"]').last().click();
  await expect(page).toHaveURL(/\/admin\/orders\/manual/);

  // dashboard: grouped sections, KPI cards, chart with the fresh order
  for (const days of ['7', '30', '90']) {
    await page.goto(`/admin?days=${days}`, { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    await expect(page.locator('.dash-kpi')).toHaveCount(6);
    await expect(page.locator('.dash-group').first()).toBeVisible();
    await expect(page.locator('.dash-period')).toBeVisible();
  }
  await expect(page.locator('.dash-chart-panel')).toBeVisible();
  await expect(page.locator('body')).toContainText('Телефонний Клієнт');
  await expectNoHorizontalOverflow(page);
});

test('dashboard goals and chart notes persist', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin', { waitUntil: 'domcontentloaded' });
  const goals = page.locator('form[action$="/admin/dashboard/goals"]');
  await goals.locator('xpath=ancestor::details').locator('summary').click();
  await goals.locator('input[name="goal_revenue"]').fill('100000');
  await goals.locator('input[name="goal_orders"]').fill('250');
  await Promise.all([page.waitForResponse((r) => r.url().includes('/admin/dashboard/goals') && r.request().method() === 'POST'), goals.locator('button[type="submit"]').click()]);
  await page.goto('/admin', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.dash-goal').first()).toContainText('100 000');

  const notes = page.locator('.dash-chart-panel details.dash-notes');
  await notes.locator('summary').click();
  await notes.locator('input[name="note"]').fill('E2E promo launch');
  await Promise.all([page.waitForResponse((r) => r.url().includes('/admin/dashboard/annotation') && r.request().method() === 'POST'), notes.locator('.dash-notes__form button[type="submit"]').click()]);
  await page.goto('/admin', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.dash-notes__list')).toContainText('E2E promo launch');
});

test('automation rule can be created, toggled and removed', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/automation', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await page.locator('.admin-tabs .admin-tab').nth(1).click();
  await page.locator('input[name="name"]').fill('E2E big order alert');
  await page.locator('select[name="event_name"]').selectOption('order_placed');
  await page.locator('select[name="action_type"]').selectOption('push');
  await page.locator('input[name="min_total"]').fill('500');
  await Promise.all([page.waitForResponse((r) => r.url().includes('/admin/automation') && r.request().method() === 'POST'), page.locator('form[action$="/admin/automation/save"] button[type="submit"]').click()]);
  await page.goto('/admin/automation', { waitUntil: 'domcontentloaded' });
  await page.locator('.admin-tabs .admin-tab').nth(0).click();
  const row = page.locator('tr', { hasText: 'E2E big order alert' });
  await expect(row).toHaveCount(1);
  await Promise.all([page.waitForResponse((r) => /automation\/\d+\/toggle/.test(r.url())), row.locator('form[action*="/toggle"] button').click()]);
  await page.goto('/admin/automation', { waitUntil: 'domcontentloaded' });
  await page.locator('.admin-tabs .admin-tab').nth(0).click();
  await expect(page.locator('tr', { hasText: 'E2E big order alert' })).toHaveCount(1);
  // an insecure webhook target is refused
  await page.locator('.admin-tabs .admin-tab').nth(1).click();
  await page.locator('input[name="name"]').fill('E2E bad hook');
  await page.locator('select[name="action_type"]').selectOption('webhook');
  await page.locator('input[name="action_target"]').fill('http://127.0.0.1/hook');
  await Promise.all([page.waitForResponse((r) => r.url().includes('/admin/automation') && r.request().method() === 'POST'), page.locator('form[action$="/admin/automation/save"] button[type="submit"]').click()]);
  await page.goto('/admin/automation', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('tr', { hasText: 'E2E bad hook' })).toHaveCount(0);
  await page.locator('.admin-tabs .admin-tab').nth(0).click();
  page.on('dialog', (d) => d.accept());
  const del = page.locator('tr', { hasText: 'E2E big order alert' }).locator('form[action*="/delete"] button');
  await del.click();
  const confirmBtn = page.locator('[data-confirm-accept], .admin-dialog button.is-danger, dialog button.is-danger');
  if (await confirmBtn.count()) await Promise.all([page.waitForResponse((r) => /automation\/\d+\/delete/.test(r.url())), confirmBtn.first().click()]);
  await page.goto('/admin/automation', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('tr', { hasText: 'E2E big order alert' })).toHaveCount(0);
});

test('push: settings page, public key and subscribe endpoint validation', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/system/push', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await page.locator('input[name="enabled"]').check();
  await page.locator('input[name="subject"]').fill('mailto:ops@example.test');
  await Promise.all([page.waitForResponse((r) => r.url().includes('/admin/system/push') && r.request().method() === 'POST'), page.locator('form[action$="/admin/system/push"] button[type="submit"]').first().click()]);
  await page.goto('/admin/system/push', { waitUntil: 'domcontentloaded' });
  const key = await page.locator('input[readonly]').first().inputValue();
  expect(key.length).toBeGreaterThan(60);
  // storefront now offers the toggle
  const shop = await page.context().newPage();
  await shop.goto('/', { waitUntil: 'domcontentloaded' });
  await expect(shop.locator('.push-toggle')).toHaveCount(1);
  // the endpoint refuses private addresses (SSRF), bad keys and foreign origins
  const base = process.env.E2E_BASE_URL!;
  const bad = await shop.request.post('/push/subscribe', { headers: { origin: base }, data: { endpoint: 'https://127.0.0.1/x', keys: { p256dh: 'a', auth: 'b' } } });
  expect(bad.status()).toBeGreaterThanOrEqual(400);
  const foreign = await shop.request.post('/push/subscribe', { headers: { origin: 'https://evil.example' }, data: { endpoint: 'https://push.example.test/x', keys: { p256dh: 'a', auth: 'b' } } });
  expect(foreign.status()).toBeGreaterThanOrEqual(400);
  const sw = await shop.request.get('/nexora-push-sw.js');
  expect(sw.status()).toBe(200);
  await shop.close();
  // switch off again so later tests see a clean storefront
  await page.locator('input[name="enabled"]').uncheck();
  await Promise.all([page.waitForResponse((r) => r.url().includes('/admin/system/push') && r.request().method() === 'POST'), page.locator('form[action$="/admin/system/push"] button[type="submit"]').first().click()]);
});

test('downloads centre: upload, public listing, download counter, type whitelist', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/content/downloads', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const dir = mkdtempSync(join(tmpdir(), 'dl-'));
  const good = join(dir, 'e2e-price.txt');
  writeFileSync(good, 'price list e2e');
  const bad = join(dir, 'evil.php');
  writeFileSync(bad, '<?php echo 1;');
  const form = page.locator('form[action$="/admin/content/downloads/upload"]');
  await form.locator('input[name="file"]').setInputFiles(good);
  await form.locator('input[name="title"]').fill('E2E Price list');
  await form.locator('input[name="group"]').fill('E2E Group');
  await Promise.all([page.waitForResponse((r) => r.url().includes('/downloads/upload') && r.request().method() === 'POST'), form.locator('button[type="submit"]').click()]);
  await page.goto('/admin/content/downloads', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('tr', { hasText: 'E2E Price list' })).toHaveCount(1);
  await page.locator('form[action$="/admin/content/downloads/upload"] input[name="file"]').setInputFiles(bad);
  await page.locator('form[action$="/admin/content/downloads/upload"] input[name="title"]').fill('E2E Evil');
  await Promise.all([page.waitForResponse((r) => r.url().includes('/downloads/upload') && r.request().method() === 'POST'), page.locator('form[action$="/admin/content/downloads/upload"] button[type="submit"]').click()]);
  await page.goto('/admin/content/downloads', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('tr', { hasText: 'E2E Evil' })).toHaveCount(0);

  const shop = await page.context().newPage();
  await shop.goto('/downloads', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(shop);
  const item = shop.locator('.downloads__item', { hasText: 'E2E Price list' });
  await expect(item).toHaveCount(1);
  const href = await item.getAttribute('href');
  const res = await shop.request.get(href!, { maxRedirects: 0 });
  expect(res.status()).toBe(302);
  await shop.goto('/downloads?q=zzzz-nothing', { waitUntil: 'domcontentloaded' });
  await expect(shop.locator('.downloads__empty')).toHaveCount(1);
  await shop.close();
});

test('category text blocks render sanitised above and below the grid', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/catalog/categories', { waitUntil: 'domcontentloaded' });
  await page.locator('a[href*="/admin/catalog/categories/"][href$="/edit"]').first().click();
  await page.waitForLoadState('domcontentloaded');
  await expectNoServerError(page);
  await page.locator('textarea[name="description"]').evaluate((el, v) => { (el as HTMLTextAreaElement).value = v; }, '<p>E2E intro</p><script>window.__x=1</script>');
  await page.locator('textarea[name="description_bottom"]').evaluate((el, v) => { (el as HTMLTextAreaElement).value = v; }, '<h2>E2E seo</h2><p onclick="alert(1)">Bottom text</p>');
  const url = page.url();
  await Promise.all([page.waitForResponse((r) => r.request().method() === 'POST' && r.url() === url), page.locator('main form button[type="submit"]').last().click()]);
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('textarea[name="description_bottom"]')).toHaveValue(/E2E seo/);
  const name = await page.locator('input[name="name"]').first().inputValue();
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const link = page.locator('main a, header a, nav a').filter({ hasText: name }).locator('visible=true').first();
  await expect(link).toBeVisible();
  await link.click();
  await page.waitForLoadState('domcontentloaded');
  await expectNoServerError(page);
  await expect(page.locator('.category-description').first()).toContainText('E2E intro');
  await expect(page.locator('.category-description--bottom')).toContainText('Bottom text');
  const html = await page.content();
  expect(html).not.toContain('window.__x=1');
  expect(html).not.toContain('onclick="alert(1)"');
});

test('custom fields: define, fill on the product, show on the storefront', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/catalog/fields', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const form = page.locator('form[action$="/admin/catalog/fields/save"]');
  await form.locator('input[name="label"]').fill('Гарантія, міс.');
  await form.locator('input[name="code"]').fill('warranty_months');
  await form.locator('select[name="field_type"]').selectOption('number');
  await Promise.all([page.waitForResponse((r) => r.url().includes('/fields/save') && r.request().method() === 'POST'), form.locator('button[type="submit"]').click()]);
  await page.goto('/admin/catalog/fields', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('tr', { hasText: 'warranty_months' })).toHaveCount(1);

  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  await page.locator('a[href*="/admin/catalog/products/"][href$="/edit"]').first().click();
  await page.waitForLoadState('domcontentloaded');
  const editUrl = page.url();
  await openProductTab(page, 'details');
  const valuesForm = page.locator('form[action$="/fields"]');
  await expect(valuesForm).toHaveCount(1);
  await valuesForm.locator('input[name^="field["]').first().fill('24');
  await Promise.all([page.waitForResponse((r) => r.url().endsWith('/fields') && r.request().method() === 'POST'), valuesForm.locator('button[type="submit"]').click()]);
  await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
  await openProductTab(page, 'details');
  await expect(page.locator('form[action$="/fields"] input[name^="field["]').first()).toHaveValue('24');
  const slug = await page.locator('input[name="slug"]').first().inputValue();
  const shop = await page.context().newPage();
  await shop.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
  await expect(shop.locator('.product-custom-fields')).toContainText('24');
  await shop.close();
  // a non-numeric value in a number field is dropped, not stored
  await page.locator('form[action$="/fields"] input[name^="field["]').first().fill('abc');
  await Promise.all([page.waitForResponse((r) => r.url().endsWith('/fields') && r.request().method() === 'POST'), page.locator('form[action$="/fields"] button[type="submit"]').click()]);
  await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
  await openProductTab(page, 'details');
  await expect(page.locator('form[action$="/fields"] input[name^="field["]').first()).toHaveValue('');
});

test('login captcha: built-in challenge gates the customer login, outage mode persists', async ({ page, browser }, testInfo) => {
  await loginAdmin(page);
  const set = async (provider: string, forms: string[], failMode: string) => {
    await page.goto('/admin/system/captcha', { waitUntil: 'domcontentloaded' });
    const form = page.locator('[data-captcha-settings]');
    await form.locator('select[name="provider"]').selectOption(provider);
    await form.locator('select[name="fail_mode"]').selectOption(failMode);
    for (const box of await form.locator('input[name="forms[]"]').all()) {
      const value = await box.getAttribute('value');
      if (forms.includes(value!)) await box.check(); else await box.uncheck();
    }
    await Promise.all([page.waitForResponse((r) => r.url().includes('/admin/system/captcha') && r.request().method() === 'POST'), form.locator('button[type="submit"]').click()]);
  };
  await set('builtin', ['login'], 'closed');
  try {
    await page.goto('/admin/system/captcha', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('select[name="fail_mode"]')).toHaveValue('closed');
    await expect(page.locator('input[name="forms[]"][value="login"]')).toBeChecked();

    const ctx = await browser.newContext({ baseURL: testInfo.project.use.baseURL });
    const shop = await ctx.newPage();
    await shop.goto('/account/login', { waitUntil: 'domcontentloaded' });
    await expect(shop.locator('[data-captcha="builtin"]')).toHaveCount(1);
    await shop.locator('input[name="_username"]').fill('nobody@example.test');
    await shop.locator('input[name="_password"]').fill('wrong-password');
    await shop.locator('input[name="mc_captcha_answer"]').fill('WRONG');
    await shop.waitForTimeout(1200);
    await shop.locator('.account-form button[type="submit"]').first().click();
    await shop.waitForURL(/\/account\/login/);
    await expect(shop.locator('[data-captcha="builtin"]')).toHaveCount(1);
    // the correct answer reaches the real authenticator (which then rejects the bad credentials)
    const token = await shop.locator('input[name="mc_captcha_token"]').inputValue();
    await shop.locator('input[name="_username"]').fill('nobody@example.test');
    await shop.locator('input[name="_password"]').fill('wrong-password');
    await shop.locator('input[name="mc_captcha_answer"]').fill(builtinAnswer(token));
    await shop.waitForTimeout(1200);
    await shop.locator('.account-form button[type="submit"]').first().click();
    await shop.waitForURL(/\/account\/login/);
    await expectNoServerError(shop);
    await ctx.close();
  } finally {
    await set('none', [], 'open');
  }
  await page.goto('/admin/system/captcha', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('select[name="fail_mode"]')).toHaveValue('open');
});
