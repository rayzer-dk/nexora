import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

const HUMAN_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')), page.locator('button[type="submit"]').click()]);
}

async function sessions(page: Page): Promise<number> {
  await page.goto('/admin/analytics/traffic?days=7', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const card = page.locator('.dash-kpi').first();
  return Number((await card.locator('.dash-kpi__value').textContent())!.replace(/\s/g, ''));
}

test.beforeEach(async ({}, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared CI data once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');
});

test('own analytics: cookie-free sessions, sources, funnel; bots are ignored', async ({ page, browser }, testInfo) => {
  await loginAdmin(page);
  const before = await sessions(page);

  // a crawler announces itself and must not be counted
  const bot = await browser.newContext({ baseURL: testInfo.project.use.baseURL, userAgent: 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' });
  const botPage = await bot.newPage();
  await botPage.goto('/', { waitUntil: 'domcontentloaded' });
  await bot.close();
  expect(await sessions(page)).toBe(before);

  const human = await browser.newContext({ baseURL: testInfo.project.use.baseURL, userAgent: HUMAN_UA });
  const shop = await human.newPage();
  await shop.goto('/?utm_source=e2e-news&utm_medium=email&utm_campaign=e2e-camp', { waitUntil: 'domcontentloaded' });
  await shop.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const href = await shop.locator('[data-product-card]').filter({ has: shop.locator('form[data-card-add-to-cart]') }).first().locator('h2 a').getAttribute('href');
  await shop.goto(href!, { waitUntil: 'domcontentloaded' });
  const variant = await shop.locator('form[action="/cart/add"] input[name="variant_id"]').first().inputValue();
  const token = await shop.locator('form[action="/cart/add"] input[name="_token"]').first().inputValue();
  const add = await human.request.post('/cart/add', { form: { variant_id: variant, quantity: '1', _token: token }, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
  expect(add.status()).toBe(200);
  await shop.goto('/checkout', { waitUntil: 'domcontentloaded' });
  const cookies = (await human.cookies()).map((c) => c.name.toLowerCase());
  expect(cookies.filter((n) => /analytics|visit|_ga|track/.test(n))).toEqual([]);
  await human.close();

  await expect.poll(async () => sessions(page), { timeout: 15000 }).toBe(before + 1);
  await expect(page.locator('.dash-funnel li').nth(1)).toContainText(/[1-9]/);
  await expect(page.locator('.dash-funnel li').nth(2)).toContainText(/[1-9]/);
  await expect(page.locator('.dash-funnel li').nth(3)).toContainText(/[1-9]/);
  await expect(page.locator('body')).toContainText('e2e-news');
  await expect(page.locator('body')).toContainText('e2e-camp');

  // switching collection off keeps the setting and stops counting
  await page.locator('input[name="enabled"]').uncheck();
  await Promise.all([page.waitForResponse((r) => r.url().includes('/analytics/traffic/settings') && r.request().method() === 'POST'), page.locator('form[action$="/analytics/traffic/settings"] button[type="submit"]').click()]);
  await page.goto('/admin/analytics/traffic', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('input[name="enabled"]')).not.toBeChecked();
  const off = await browser.newContext({ baseURL: testInfo.project.use.baseURL, userAgent: HUMAN_UA + ' Off' });
  await (await off.newPage()).goto('/', { waitUntil: 'domcontentloaded' });
  await off.close();
  expect(await sessions(page)).toBe(before + 1);
  await page.goto('/admin/analytics/traffic', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="enabled"]').check();
  await Promise.all([page.waitForResponse((r) => r.url().includes('/analytics/traffic/settings') && r.request().method() === 'POST'), page.locator('form[action$="/analytics/traffic/settings"] button[type="submit"]').click()]);
});

test('data and storage page lists tables and runs the cleanup', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/system/data', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('table.admin-table tbody tr').first()).toBeVisible();
  await expect(page.locator('table.admin-table')).toContainText('mc_');
  await page.locator('form[action$="/admin/system/data/run"] button[type="submit"]').click();
  await Promise.all([page.waitForResponse((r) => r.url().includes('/admin/system/data/run') && r.request().method() === 'POST'), page.locator('[data-confirm-accept]').click()]);
  await page.goto('/admin/system/data', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
});

test('AI assistant: key stored encrypted, limits and errors enforced, helpers appear only when enabled', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/catalog/categories', { waitUntil: 'domcontentloaded' });
  await page.locator('a[href*="/admin/catalog/categories/"][href$="/edit"]').first().click();
  await page.waitForLoadState('domcontentloaded');
  const categoryUrl = page.url();
  await expect(page.locator('[data-ai-box]')).toHaveCount(0);

  await page.goto('/admin/system/ai', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const card = page.locator('.ai-provider').filter({ hasText: 'OpenAI' });
  await card.locator('input[name="enabled"]').check();
  await card.locator('input[name="model_custom"]').fill('gpt-5');
  await card.locator('input[name="api_key"]').fill('sk-e2e-key-1234567890abcdef');
  await Promise.all([page.waitForResponse((r) => r.url().includes('/admin/system/ai/save') && r.request().method() === 'POST'), card.locator('button[type="submit"]').first().click()]);
  await page.goto('/admin/system/ai', { waitUntil: 'domcontentloaded' });
  expect(await page.content()).not.toContain('sk-e2e-key');
  await expect(page.locator('.ai-provider').filter({ hasText: 'OpenAI' }).locator('.admin-badge').first()).toHaveClass(/is-ok/);

  // the helper is offered on the category form now
  await page.goto(categoryUrl, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-ai-box]')).toHaveCount(1);

  // API guards: bad token, missing input, then the daily limit
  await page.goto('/admin/system/ai', { waitUntil: 'domcontentloaded' });
  const token = await page.locator('[data-ai-playground]').getAttribute('data-ai-token');
  const bad = await page.request.post('/admin/api/ai/task', { form: { _token: 'x', task: 'reply', provider: 'openai' } });
  expect(bad.status()).toBe(403);
  const missing = await page.request.post('/admin/api/ai/task', { form: { _token: token!, task: 'reply', provider: 'openai' } });
  expect(missing.status()).toBe(422);
  const unknown = await page.request.post('/admin/api/ai/task', { form: { _token: token!, task: 'nope', provider: 'openai', 'fields[x]': 'y' } });
  expect(unknown.status()).toBe(422);
  await page.locator('input[name="daily_limit"]').fill('0');
  await Promise.all([page.waitForResponse((r) => r.url().includes('/admin/system/ai/save') && r.request().method() === 'POST'), page.locator('input[name="daily_limit"]').locator('xpath=ancestor::form').locator('button[type="submit"]').click()]);
  await page.goto('/admin/system/ai', { waitUntil: 'domcontentloaded' });
  const blocked = await page.request.post('/admin/api/ai/task', { form: { _token: (await page.locator('[data-ai-playground]').getAttribute('data-ai-token'))!, task: 'reply', provider: 'openai', 'fields[message]': 'Hello' } });
  expect(blocked.status()).toBe(422);
  expect((await blocked.json()).message).toBeTruthy();
  await page.locator('input[name="daily_limit"]').fill('200');
  await Promise.all([page.waitForResponse((r) => r.url().includes('/admin/system/ai/save') && r.request().method() === 'POST'), page.locator('input[name="daily_limit"]').locator('xpath=ancestor::form').locator('button[type="submit"]').click()]);

  // remove the key: the helper disappears again
  await page.goto('/admin/system/ai', { waitUntil: 'domcontentloaded' });
  await page.locator('form[action$="/admin/system/ai/key-delete"] button[type="submit"]').first().click();
  await Promise.all([page.waitForResponse((r) => r.url().includes('/ai/key-delete') && r.request().method() === 'POST'), page.locator('[data-confirm-accept]').click()]);
  await page.goto(categoryUrl, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-ai-box]')).toHaveCount(0);
});
