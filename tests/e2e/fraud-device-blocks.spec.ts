import { execFileSync } from 'node:child_process';
import { expect, test, type Browser, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

// Contains "headless": excluded from own analytics so this spec cannot skew the traffic spec running in parallel.
const HUMAN_UA =
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/127.0.0.0 Safari/537.36';
const suffix = Date.now();

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')),
    page.locator('button[type="submit"]').click(),
  ]);
}

// eslint-disable-next-line no-empty-pattern
test.beforeEach(async ({}, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared CI data once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');
});

/** Adds the first purchasable product to the cart and fills the checkout; returns without submitting. */
async function fillCheckout(page: Page, email: string): Promise<void> {
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const href = await page.locator('[data-product-card] h2 a').first().getAttribute('href');
  await page.goto(href!, { waitUntil: 'domcontentloaded' });
  const add = page.waitForResponse((r) => r.url().endsWith('/cart/add') && r.request().method() === 'POST');
  await page.locator('form[data-buy-actions] [data-primary-buy]').click();
  expect((await add).status()).toBeLessThan(500);
  await page.goto('/checkout', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-checkout-form]')).toBeVisible();
  await page.locator('[name="name"]').fill('E2E Buyer');
  await page.locator('[name="phone"]').fill('+380501234567');
  const mail = page.locator('[data-checkout-form] [name="email"]');
  if (await mail.count()) await mail.fill(email);
  const city = page.locator('[data-delivery-city]');
  if (await city.count()) {
    await city.fill('Київ');
    const manual = page.locator('[name="delivery_manual"]');
    if (await manual.count()) {
      await manual.evaluate((el) => {
        const input = el as HTMLInputElement;
        input.value = 'Київ, тестове відділення 1';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
      });
    }
  }
  const region = page.locator('select[name="delivery_region"]');
  if (await region.count()) await region.selectOption({ index: 1 });
  const cod = page.locator('input[name="payment_method"][value="cash_on_delivery"]');
  if (await cod.count()) await cod.check();
}

async function guest(browser: Browser, baseURL: string | undefined): Promise<Page> {
  const ctx = await browser.newContext({ baseURL, userAgent: HUMAN_UA });
  return ctx.newPage();
}

async function saveFraudSettings(page: Page, values: { review?: string; device?: boolean }): Promise<void> {
  await page.goto('/admin/system/fraud', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const form = page.locator('form[action="/admin/system/fraud/save"]');
  if (values.review) await form.locator('input[name="review_score"]').fill(values.review);
  if (values.device !== undefined) await form.locator('input[name="device_confirm"]').setChecked(values.device);
  await form.locator('button[type="submit"]').click();
  await expect(page.locator('body')).toContainText('Налаштування збережено');
}

test('anti-fraud: blocklist stops checkout, risky orders are flagged with reasons and can be cleared', async ({
  page,
  browser,
}, testInfo) => {
  const baseURL = testInfo.project.use.baseURL;
  await loginAdmin(page);
  const blocked = `blocked-${suffix}@example.test`;

  await page.goto('/admin/system/fraud', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const add = page.locator('form[action="/admin/system/fraud/block"]');
  await add.locator('select[name="kind"]').selectOption('email');
  await add.locator('input[name="value"]').fill(blocked);
  await add.locator('button[type="submit"]').click();
  await expect(page.locator('body')).toContainText(blocked);

  const buyer = await guest(browser, baseURL);
  await fillCheckout(buyer, blocked);
  await buyer.locator('button.place-order').click();
  await expect(buyer.locator('body')).toContainText('Не вдалося оформити замовлення онлайн');
  expect(buyer.url()).not.toContain('/checkout/success');
  await buyer.context().close();

  // remove the entry again: checkout works and, with a low threshold, a disposable address is flagged
  await page.goto('/admin/system/fraud', { waitUntil: 'domcontentloaded' });
  await page.locator('tr', { hasText: blocked }).locator('form[action*="/delete"] button').click();
  await page.locator('[data-confirm-accept]').click();
  await expect(page.locator('body')).not.toContainText(blocked);
  await saveFraudSettings(page, { review: '10' });

  const risky = await guest(browser, baseURL);
  await fillCheckout(risky, `risky-${suffix}@mailinator.com`);
  await Promise.all([
    risky.waitForURL(/\/checkout\/success\//, { timeout: 20_000 }),
    risky.locator('button.place-order').click(),
  ]);
  await risky.context().close();

  await page.goto('/admin/system/fraud', { waitUntil: 'domcontentloaded' });
  const row = page.locator('tr[data-fraud-row]', { hasText: `risky-${suffix}@mailinator.com` });
  await expect(row).toBeVisible();
  await expect(row).toContainText('Одноразова пошта');
  await row.locator('button[value="clear"]').click();
  await expect(page.locator('tr[data-fraud-row]', { hasText: `risky-${suffix}@mailinator.com` })).toContainText(
    'Перевірено',
  );
  await saveFraudSettings(page, { review: '40' });
});

test('new-device confirmation gates the account until the e-mailed code is entered, then remembers the browser', async ({
  page,
  browser,
}, testInfo) => {
  const baseURL = testInfo.project.use.baseURL;
  await loginAdmin(page);
  await saveFraudSettings(page, { device: true });

  const email = `e2e-device-${suffix}@example.test`;
  const password = 'Nexora-Customer-2026!';
  const shop = await guest(browser, baseURL);
  await shop.goto('/account/register', { waitUntil: 'domcontentloaded' });
  const reg = shop.locator('form.account-form');
  await reg.locator('input[name="display_name"]').fill('E2E Device');
  await reg.locator('input[name="email"]').fill(email);
  await reg.locator('input[name="password"]').fill(password);
  await Promise.all([shop.waitForURL(/\/account\/login/), reg.locator('button[type="submit"]').click()]);

  await shop.locator('input[name="_username"]').fill(email);
  await shop.locator('input[name="_password"]').fill(password);
  await Promise.all([
    shop.waitForURL(/\/account\/device-verify/),
    shop.locator('form.account-form button[type="submit"]').click(),
  ]);

  await shop.goto('/account', { waitUntil: 'domcontentloaded' });
  expect(new URL(shop.url()).pathname).toBe('/account/device-verify');

  const verifyForm = shop.locator('form.account-form').first();
  await verifyForm.locator('input[name="code"]').fill('000000');
  await verifyForm.locator('button[type="submit"]').click();
  await expect(shop.locator('.store-notice.is-error')).toContainText('Невірний або прострочений код');

  const code = execFileSync('php', ['tests/e2e/read-verification-code.php', email, 'customer_device_code'], {
    encoding: 'utf8',
    env: process.env,
  }).trim();
  expect(code).toMatch(/^\d{6}$/);
  await shop.locator('form.account-form').first().locator('input[name="code"]').fill(code);
  await Promise.all([
    shop.waitForURL(/\/account(?:\?.*)?$/),
    shop.locator('form.account-form').first().locator('button[type="submit"]').click(),
  ]);
  expect(new URL(shop.url()).pathname).toBe('/account');
  expect((await shop.context().cookies()).map((c) => c.name)).toContain('nx_dev');

  // sign out and in again from the same browser: no second challenge
  await shop.locator('form[action="/account/logout"] button').first().click();
  await shop.goto('/account/login', { waitUntil: 'domcontentloaded' });
  await shop.locator('input[name="_username"]').fill(email);
  await shop.locator('input[name="_password"]').fill(password);
  await Promise.all([
    shop.waitForURL(/\/account(?:\?.*)?$/),
    shop.locator('form.account-form button[type="submit"]').click(),
  ]);
  expect(new URL(shop.url()).pathname).toBe('/account');
  await shop.context().close();

  await saveFraudSettings(page, { device: false });
});

test('product info blocks render on the product page and articles link to products in both directions', async ({
  page,
}) => {
  await loginAdmin(page);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  const edit = page.locator('a[href$="/edit"]').first();
  const editHref = await edit.getAttribute('href');
  await page.goto(editHref!, { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const sku = await page.locator('input[name="sku"]').first().inputValue();
  expect(sku).not.toBe('');

  const blocks = page.locator('#info-blocks form');
  const table = blocks.locator('fieldset').last();
  await table.locator('select[name$="[type]"]').selectOption('table');
  await table.locator('input[name$="[title]"]').fill(`Size chart ${suffix}`);
  await table.locator('textarea').fill('Size | Chest\nS | 90\nM | 98');
  await blocks.locator('button[type="submit"]').click();
  await expect(page.locator('#info-blocks textarea').filter({ hasText: 'Size | Chest' }).first()).toBeVisible();

  // an article that references the product by SKU
  await page.goto('/admin/content/blog/new', { waitUntil: 'domcontentloaded' });
  const form = page.locator('form[data-blog-form]');
  const title = `E2E Linked Article ${suffix}`;
  const slug = `e2e-linked-article-${suffix}`;
  await form.locator('input[name="title"]').fill(title);
  await form.locator('input[name="slug"]').fill(slug);
  await form.locator('textarea[name="excerpt"]').fill('Linked article excerpt.');
  await form.locator('input[name="product_skus"]').fill(sku);
  await form.locator('select[name="status"]').selectOption('published');
  const toggle = page.locator('.rich-editor__source-toggle').first();
  await toggle.click();
  await page.locator('.rich-editor__source').first().fill('<h2>One</h2><p>Body text of the linked article.</p>');
  await toggle.click();
  await Promise.all([
    page.waitForURL(/\/admin\/content\/blog\/\d+\/edit/),
    form.locator('button[type="submit"]').first().click(),
  ]);
  await expect(page.locator('input[name="product_skus"]')).toHaveValue(sku);

  // storefront: article lists the product, product lists the article and shows the size chart
  await page.goto(`/blog/${slug}`, { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const productLink = page.locator('.article-products__item a').first();
  await expect(productLink).toBeVisible();
  await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/blog/')), productLink.click()]);
  const chart = page.locator('.product-info-block--table', { hasText: `Size chart ${suffix}` });
  await expect(chart).toHaveCount(1);
  await expect(chart.locator('td').first()).toHaveText('S');
  await expect(page.locator('.product-articles__list a', { hasText: title })).toBeVisible();
});

test('payment webhooks reject unsigned callbacks and the store hides Google login until configured', async ({
  request,
  page,
}) => {
  const liqpay = await request.post('/webhooks/payments/liqpay', {
    form: { data: Buffer.from('{"order_id":"X","status":"success"}').toString('base64'), signature: 'AAAA' },
  });
  expect(liqpay.status()).toBe(401);
  const wfp = await request.post('/webhooks/payments/wayforpay', {
    data: { merchantAccount: 'x', orderReference: 'X', transactionStatus: 'Approved', merchantSignature: 'bad' },
  });
  expect(wfp.status()).toBe(401);
  await page.goto('/account/login', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-google-login]')).toHaveCount(0);
  const start = await request.get('/account/login/google', { maxRedirects: 0 });
  expect(start.status()).toBe(404);
});
