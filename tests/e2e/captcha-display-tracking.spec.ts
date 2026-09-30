import { createHmac } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

/** The app's kernel secret: `.env.local` (written by the installer) wins over the process environment. */
function appSecret(): string {
  if (existsSync('.env.local')) {
    const match = /^APP_SECRET=(.+)$/m.exec(readFileSync('.env.local', 'utf8'));
    if (match) return match[1].trim().replace(/^['"]|['"]$/g, '');
  }
  return process.env.APP_SECRET ?? '';
}
const SECRET = appSecret();
const ALPHABET = 'ACDEFHJKLMNPRTUVWXY34679';

/** Mirrors BuiltinCaptcha::answer() so the test can play the "human" (the image itself is not machine-readable here). */
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

async function setCaptcha(page: Page, provider: string, forms: string[]): Promise<void> {
  await page.goto('/admin/system/captcha', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const form = page.locator('[data-captcha-settings]');
  await form.locator('select[name="provider"]').selectOption(provider);
  for (const box of await form.locator('input[name="forms[]"]').all()) {
    const value = await box.getAttribute('value');
    if (forms.includes(value!)) await box.check(); else await box.uncheck();
  }
  await form.locator('button[type="submit"]').click();
  await expect(page.locator('.admin-notice.is-success')).toHaveCount(1);
}

test('captcha: admin picks provider and forms; built-in captcha gates the newsletter form', async ({ page, browser }, testInfo) => {
  await loginAdmin(page);
  // external providers demand keys
  await page.goto('/admin/system/captcha', { waitUntil: 'domcontentloaded' });
  await page.locator('select[name="provider"]').selectOption('turnstile');
  await page.locator('[data-captcha-settings] button[type="submit"]').click();
  await expect(page.locator('.admin-notice.is-error')).toHaveCount(1);

  await setCaptcha(page, 'builtin', ['newsletter']);
  try {
    const ctx = await browser.newContext({ baseURL: testInfo.project.use.baseURL });
    const shop = await ctx.newPage();
    await shop.goto('/', { waitUntil: 'networkidle' });
    const form = shop.locator('form.newsletter-signup__form');
    await expect(form.locator('[data-captcha="builtin"]')).toHaveCount(1);
    await expect(form.locator('.captcha__image')).toHaveCount(1);
    // the image endpoint serves a PNG for the issued token
    const token = await form.locator('input[name="mc_captcha_token"]').inputValue();
    const png = await shop.request.get(`/captcha/image/${token}`);
    expect(png.status()).toBe(200);
    expect(png.headers()['content-type']).toBe('image/png');

    const email = `captcha-${Date.now()}@example.test`;
    await shop.waitForTimeout(1200);
    await form.locator('input[name="email"]').fill(email);
    await form.locator('input[name="mc_captcha_answer"]').fill('WRONG');
    await form.locator('button[type="submit"]').click();
    await expect(shop.locator('.store-notice.is-error')).toHaveCount(1);

    // correct answer passes; the same token cannot be replayed
    await shop.waitForTimeout(1200);
    const fresh = await shop.locator('form.newsletter-signup__form input[name="mc_captcha_token"]').inputValue();
    await shop.locator('form.newsletter-signup__form input[name="email"]').fill(email);
    await shop.locator('form.newsletter-signup__form input[name="mc_captcha_answer"]').fill(builtinAnswer(fresh));
    await shop.locator('form.newsletter-signup__form button[type="submit"]').click();
    await expect(shop.locator('.store-notice.is-success')).toHaveCount(1);
    const replay = await shop.request.post('/newsletter/subscribe', { form: { _token: 'x', email, mc_captcha_token: fresh, mc_captcha_answer: builtinAnswer(fresh) }, maxRedirects: 0 });
    expect([302, 403]).toContain(replay.status());

    // a form that is not ticked has no captcha
    await shop.goto('/account/register', { waitUntil: 'domcontentloaded' });
    await expect(shop.locator('.account-form [data-captcha]')).toHaveCount(0);
    // the refresh button issues a new challenge
    await shop.goto('/', { waitUntil: 'networkidle' });
    const before = await shop.locator('form.newsletter-signup__form input[name="mc_captcha_token"]').inputValue();
    await shop.locator('form.newsletter-signup__form [data-captcha-refresh]').click();
    await expect.poll(() => shop.locator('form.newsletter-signup__form input[name="mc_captcha_token"]').inputValue()).not.toBe(before);
    await ctx.close();
  } finally {
    await setCaptcha(page, 'none', []);
  }
});

test('display variants: card style, columns, layout, sticky header, quick order and benefits', async ({ page, browser }, testInfo) => {
  await loginAdmin(page);
  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const panel = page.locator('[data-display-settings]');
  await panel.locator('input[name="display_card_style"][value="overlay"]').check();
  await panel.locator('input[name="display_card_columns"][value="3"]').check();
  await panel.locator('input[name="display_product_layout"][value="stacked"]').check();
  await panel.locator('input[name="display_category_style"][value="chips"]').check();
  await panel.locator('input[name="display_sticky_header"]').check();
  await panel.locator('input[name="display_quick_order"]').check();
  await panel.locator('textarea[name="display_benefits"]').fill('Free delivery over 2000\nTwo-year warranty');
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/admin/appearance/storefront')),
    page.locator('form[data-appearance-media] .admin-form-actions button[type="submit"]').click(),
  ]);
  // the success notice is turned into a transient toast, so assert persistence instead
  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-display-settings] input[name="display_card_style"][value="overlay"]')).toBeChecked();

  try {
    const ctx = await browser.newContext({ baseURL: testInfo.project.use.baseURL });
    const shop = await ctx.newPage();
    await shop.goto('/catalog', { waitUntil: 'networkidle' });
    const body = shop.locator('body');
    await expect(body).toHaveAttribute('data-card-style', 'overlay');
    await expect(body).toHaveAttribute('data-card-columns', '3');
    await expect(body).toHaveAttribute('data-category-style', 'chips');
    await expect(body).toHaveAttribute('data-product-layout', 'stacked');
    await expect(body).toHaveAttribute('data-sticky-header', '');
    // overlay cards keep the title readable and the grid really has 3 columns on desktop
    const cols = await shop.locator('.product-grid').first().evaluate((el) => getComputedStyle(el).gridTemplateColumns.split(' ').length);
    expect(cols).toBe(3);

    await shop.goto('/iphone-x', { waitUntil: 'networkidle' });
    await expect(shop.locator('.product-benefits li')).toHaveCount(2);
    // live total appears for quantities above one
    await shop.locator('[data-buy-actions] [data-qty-plus]').click();
    await expect(shop.locator('[data-live-total]')).toBeVisible();
    // quick order creates an inquiry
    await shop.locator('[data-quick-order-open]').click();
    const dialog = shop.locator('[data-quick-order-dialog]');
    await expect(dialog).toBeVisible();
    await shop.waitForTimeout(1200);
    await dialog.locator('input[name="name"]').fill('Quick Buyer');
    await dialog.locator('input[name="phone"]').fill('+380501112233');
    await dialog.locator('button[type="submit"]').click();
    await expect(dialog).toBeHidden();
    await ctx.close();

    await page.goto('/admin/commerce/inquiries', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('body')).toContainText('Quick Buyer');
    await expect(page.locator('body')).toContainText('quick_order');
  } finally {
    await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
    const p = page.locator('[data-display-settings]');
    await p.locator('input[name="display_card_style"][value="classic"]').check();
    await p.locator('input[name="display_card_columns"][value="4"]').check();
    await p.locator('input[name="display_product_layout"][value="classic"]').check();
    await p.locator('input[name="display_category_style"][value="classic"]').check();
    await p.locator('input[name="display_sticky_header"]').uncheck();
    await p.locator('input[name="display_quick_order"]').uncheck();
    await p.locator('textarea[name="display_benefits"]').fill('');
    await page.locator('.admin-form-actions button[type="submit"]').last().click();
    await expect(page.locator('.admin-notice.is-success')).toHaveCount(1);
  }
});

test('analytics tags load only after consent and only with validated IDs', async ({ page, browser }, testInfo) => {
  await loginAdmin(page);
  await page.goto('/admin/system/tracking', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await page.locator('input[name="ga4_id"]').fill('<script>');
  await page.locator('form button[type="submit"]').last().click();
  await expect(page.locator('.admin-notice.is-error')).toHaveCount(1);
  await page.locator('input[name="ga4_id"]').fill('G-TEST123456');
  await page.locator('input[name="meta_pixel_id"]').fill('123456789012345');
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/admin/system/tracking')),
    page.locator('form button[type="submit"]').last().click(),
  ]);
  await page.goto('/admin/system/tracking', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('input[name="ga4_id"]')).toHaveValue('G-TEST123456');

  try {
    const ctx = await browser.newContext({ baseURL: testInfo.project.use.baseURL });
    const shop = await ctx.newPage();
    const requested: string[] = [];
    shop.on('request', (r) => requested.push(r.url()));
    await shop.route(/googletagmanager\.com|connect\.facebook\.net|google-analytics\.com|facebook\.com/, (route) => route.abort());
    await shop.goto('/', { waitUntil: 'networkidle' });
    // before consent: only inert placeholders, nothing requested, nothing executed
    await expect(shop.locator('script[type="text/plain"][data-consent-category="analytics"]')).toHaveCount(2);
    await expect(shop.locator('script[type="text/plain"][data-consent-category="marketing"]')).toHaveCount(1);
    expect(requested.filter((u) => /googletagmanager|facebook/.test(u))).toEqual([]);
    // analytics only -> Google tag activates, Meta does not
    await shop.locator('[data-consent-open]').first().click();
    await shop.locator('[data-commerce-consent] input[name="consent_analytics"]').check();
    await shop.locator('[data-consent-save]').click();
    await expect.poll(() => requested.some((u) => u.includes('googletagmanager.com/gtag/js?id=G-TEST123456'))).toBe(true);
    expect(requested.some((u) => u.includes('connect.facebook.net'))).toBe(false);
    // accept all -> Meta pixel loader is requested too
    await shop.locator('[data-consent-open]').first().click();
    await shop.locator('[data-consent-accept-all]').click();
    await expect.poll(() => requested.some((u) => u.includes('connect.facebook.net'))).toBe(true);
    await ctx.close();
  } finally {
    await page.goto('/admin/system/tracking', { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="ga4_id"]').fill('');
    await page.locator('input[name="meta_pixel_id"]').fill('');
    await page.locator('form button[type="submit"]').last().click();
    await expect(page.locator('.admin-notice.is-success')).toHaveCount(1);
  }
});
