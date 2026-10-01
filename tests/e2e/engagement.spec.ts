import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

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

test('floating contact buttons: admin configures them, storefront shows them, call-back request reaches inquiries', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/appearance/contact-widget', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const form = page.locator('[data-contact-widget-form]');

  // invalid Messenger link is rejected with a message and nothing is stored
  await form.locator('input[name="enabled"]').check();
  await form.locator('input[name="messenger"]').fill('https://evil.example/page');
  await form.locator('button[type="submit"]').click();
  await expect(page.locator('.admin-notice.is-error')).toHaveCount(1);

  await form.locator('input[name="enabled"]').check();
  await form.locator('input[name="phone"]').fill('+380 44 123 45 67');
  await form.locator('input[name="email"]').fill('shop@example.test');
  await form.locator('input[name="viber"]').fill('+380501234567');
  await form.locator('input[name="messenger"]').fill('https://m.me/nexora.page');
  await form.locator('input[name="telegram"]').fill('@nexora_shop');
  await form.locator('button[type="submit"]').click();
  await expect(page.locator('.admin-notice.is-success')).toHaveCount(1);

  try {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const widget = page.locator('[data-contact-widget]');
    await expect(widget).toBeVisible();
    await expect(widget.locator('.cw__list')).toBeHidden();
    await widget.locator('[data-cw-toggle]').click();
    await expect(widget.locator('.cw__list')).toBeVisible();
    await expect(widget.locator('[data-cw-action="call"]')).toHaveAttribute('href', 'tel:+380441234567');
    await expect(widget.locator('[data-cw-action="write"]')).toHaveAttribute('href', 'mailto:shop@example.test');
    await expect(widget.locator('[data-cw-action="viber"]')).toHaveAttribute('href', 'viber://chat?number=%2B380501234567');
    await expect(widget.locator('[data-cw-action="messenger"]')).toHaveAttribute('href', 'https://m.me/nexora.page');
    await expect(widget.locator('[data-cw-action="telegram"]')).toHaveAttribute('href', 'https://t.me/nexora_shop');

    await widget.locator('[data-cw-callback]').click();
    const dialog = page.locator('[data-cw-dialog]');
    await expect(dialog).toBeVisible();
    await dialog.locator('input[name="name"]').fill('E2E Callback Customer');
    await dialog.locator('input[name="phone"]').fill('+380671112233');
    await dialog.locator('button[type="submit"]').click();
    await expect(dialog).toBeHidden();

    await page.goto('/admin/commerce/inquiries', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('body')).toContainText('E2E Callback Customer');
    await expect(page.locator('body')).toContainText('+380671112233');
  } finally {
    await page.goto('/admin/appearance/contact-widget', { waitUntil: 'domcontentloaded' });
    await page.locator('[data-contact-widget-form] input[name="enabled"]').uncheck();
    await page.locator('[data-contact-widget-form] button[type="submit"]').click();
    await expect(page.locator('.admin-notice.is-success')).toHaveCount(1);
  }
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-contact-widget]')).toHaveCount(0);
});

test('product page has a compact share popover with links and copy-link', async ({ page, context }) => {
  await context.grantPermissions(['clipboard-read', 'clipboard-write']).catch(() => undefined);
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const href = await page.locator('[data-product-card] h2 a').first().getAttribute('href');
  await page.goto(href!, { waitUntil: 'domcontentloaded' });
  const share = page.locator('.product-page [data-share-links]').first();
  await expect(share).toBeVisible();
  await share.locator('summary').click();
  await expect(share.locator('a[href^="https://www.facebook.com/sharer/sharer.php?u="]')).toHaveCount(1);
  await expect(share.locator('a[href^="https://t.me/share/url?url="]')).toHaveCount(1);
  await share.locator('[data-copy-link]').click();
  await expect(page.locator('.storefront-toast')).toBeVisible();
});

test('IndexNow: key file proves ownership, settings save, submit is blocked on a non-public host', async ({ page, request }) => {
  await loginAdmin(page);
  await page.goto('/admin/system/seo-indexnow', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const keyUrl = (await page.locator('a[href$=".txt"] code').innerText()).trim();
  const key = keyUrl.replace(/^.*\//, '').replace(/\.txt$/, '');
  expect(key).toMatch(/^[a-f0-9]{32}$/);
  const ok = await request.get(`/${key}.txt`);
  expect(ok.status()).toBe(200);
  expect((await ok.text()).trim()).toBe(key);
  const wrong = await request.get(`/${'0'.repeat(32)}.txt`);
  expect(wrong.status()).toBe(404);
  await expect(page.locator('[data-indexnow-unusable]')).toBeVisible(); // http://127.0.0.1 is not a public https host
  await page.locator('input[name="enabled"]').check();
  await page.locator('form:has(input[name="enabled"]) button[type="submit"]').click();
  await expect(page.locator('.admin-notice.is-success')).toHaveCount(1);
  await expect(page.locator('input[name="enabled"]')).toBeChecked();
  await page.locator('input[name="enabled"]').uncheck();
  await page.locator('form:has(input[name="enabled"]) button[type="submit"]').click();
  await expect(page.locator('.admin-notice.is-success')).toHaveCount(1);
});

test('e-mail templates can be overridden per language and restored', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/commerce/notification-templates', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const card = page.locator('[data-notification-template="order.created"]');
  await card.locator('input[name="subject"]').fill('E2E: thanks for order %order_number%');
  await card.locator('textarea[name="body"]').fill('Hello %customer_name%,\nwe are packing your order %order_number% (%total%).');
  await card.locator('form:has(input[name="body"], textarea[name="body"]) button[type="submit"]').first().click();
  await expect(page.locator('.admin-notice.is-success')).toHaveCount(1);
  const saved = page.locator('[data-notification-template="order.created"]');
  await expect(saved.locator('input[name="subject"]')).toHaveValue('E2E: thanks for order %order_number%');
  await saved.locator('form[data-confirm] button[type="submit"]').click();
  await expect(page.locator('[data-admin-confirm] [data-confirm-accept]')).toBeVisible();
  await page.locator('[data-admin-confirm] [data-confirm-accept]').click();
  await expect(page.locator('[data-notification-template="order.created"] input[name="subject"]')).not.toHaveValue('E2E: thanks for order %order_number%');
});

test('live chat: vendor script and CSP appear only when configured, and the script waits for cookie consent', async ({ page, browser }, testInfo) => {
  await loginAdmin(page);
  await page.goto('/admin/appearance/contact-widget', { waitUntil: 'domcontentloaded' });
  const chat = page.locator('[data-chat-settings] form');

  await chat.locator('select[name="provider"]').selectOption('tawk');
  await chat.locator('textarea[name="embed"]').fill('<script>alert(1)</script>');
  await chat.locator('button[type="submit"]').click();
  await expect(page.locator('.admin-notice.is-error')).toHaveCount(1); // garbage is rejected

  await page.locator('[data-chat-settings] select[name="provider"]').selectOption('tawk');
  await page.locator('[data-chat-settings] textarea[name="embed"]').fill("s1.src='https://embed.tawk.to/5f8a1b2c3d4e5f6a7b8c9d0e/1hab2cdef';");
  await page.locator('[data-chat-settings] button[type="submit"]').click();
  await expect(page.locator('.admin-notice.is-success')).toHaveCount(1);

  const guest = await browser.newContext({ baseURL: testInfo.project.use.baseURL });
  const shop = await guest.newPage();
  const vendorRequests: string[] = [];
  await shop.route('https://embed.tawk.to/**', (route) => { vendorRequests.push(route.request().url()); return route.fulfill({ status: 200, contentType: 'application/javascript', body: '/* stub */' }); });
  try {
    const response = await shop.goto('/', { waitUntil: 'domcontentloaded' });
    expect(response?.headers()['content-security-policy']).toContain('https://embed.tawk.to');
    await expect(shop.locator('[data-chat-widget]')).toHaveCount(1);
    await shop.waitForTimeout(800);
    expect(vendorRequests, 'no vendor request before consent').toEqual([]);
    await shop.locator('[data-consent-accept-all]').click();
    await expect.poll(() => vendorRequests.length, { timeout: 5_000 }).toBe(1);
    expect(vendorRequests[0]).toBe('https://embed.tawk.to/5f8a1b2c3d4e5f6a7b8c9d0e/1hab2cdef');
  } finally {
    await guest.close();
  }

  // switching the chat off removes the container and the CSP extension
  await page.goto('/admin/appearance/contact-widget', { waitUntil: 'domcontentloaded' });
  await page.locator('[data-chat-settings] select[name="provider"]').selectOption('none');
  await page.locator('[data-chat-settings] button[type="submit"]').click();
  await expect(page.locator('.admin-notice.is-success')).toHaveCount(1);
  const off = await page.request.get('/');
  expect(off.headers()['content-security-policy']).not.toContain('tawk.to');
  expect(await off.text()).not.toContain('data-chat-widget');
});
