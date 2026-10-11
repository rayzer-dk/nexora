import { expect, test, type Page } from '@playwright/test';

// Release 3.97.24: keys and switches of payment services typed in the admin, and the instalment methods that use them.

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

test('instalments are switched on in the admin and offered at the checkout, then switched off', async ({ page, browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared settings once.');
  test.setTimeout(120_000);
  await loginAdmin(page);
  await page.goto('/admin/system/integrations/connections/payments', { waitUntil: 'domcontentloaded' });
  const section = page.locator('#conn-mono_parts');
  await expect(section).toBeAttached();
  const form = section.locator('form');
  await section.locator('summary').click();
  await form.locator('select[name="key[MONO_PARTS_ENABLED]"]').selectOption('1');
  await form.locator('input[name="key[MONO_PARTS_STORE_ID]"]').fill('test_store_with_confirm');
  await form.locator('input[name="key[MONO_PARTS_SECRET]"]').fill('e2e-secret');
  await Promise.all([page.waitForURL(/connections\/payments/), form.locator('button[type="submit"]').click()]);
  expect(await page.content()).not.toContain('e2e-secret');
  await expect(page.locator('#conn-mono_parts .admin-badge').first()).toContainText(/Підключено|Connected/);

  // the shopper sees the method at the checkout
  const shop = await (await browser.newContext()).newPage();
  await shop.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const card = shop.locator('[data-product-card]').filter({ has: shop.locator('form[data-card-add-to-cart]') }).first();
  const added = shop.waitForResponse((r) => r.url().endsWith('/cart/add') && r.request().method() === 'POST');
  await card.locator('form[data-card-add-to-cart] button[type="submit"]').click();
  await added;
  await shop.goto('/checkout', { waitUntil: 'domcontentloaded' });
  await expect(shop.locator('input[name="payment_method"][value="mono_parts"]')).toBeAttached();
  await shop.close();

  // the bank callback is refused without the signature
  const refused = await page.request.post('/webhooks/payments/mono_parts', { data: { order_id: 'x', state: 'FAIL' } });
  expect(refused.status()).toBe(401);

  // switch off again and forget the secret
  await page.goto('/admin/system/integrations/connections/payments', { waitUntil: 'domcontentloaded' });
  const again = page.locator('#conn-mono_parts');
  if (!(await again.locator('details').evaluate((node) => (node as HTMLDetailsElement).open))) await again.locator('summary').click();
  await again.locator('select[name="key[MONO_PARTS_ENABLED]"]').selectOption('0');
  await again.locator('input[name="clear[MONO_PARTS_SECRET]"]').check();
  await again.locator('input[name="clear[MONO_PARTS_STORE_ID]"]').check();
  await Promise.all([page.waitForURL(/connections\/payments/), again.locator('button[type="submit"]').click()]);
  await expect(page.locator('#conn-mono_parts .admin-badge').first()).toContainText(/Вимкнено|Off/);
});
