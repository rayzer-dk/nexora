import { expect, test, type Page } from '@playwright/test';

// Release 3.97.24: the checkout builder can switch the region choice and the city / branch search off; the buyer then types one address.

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

async function publish(page: Page, mutate: (layout: { blocks: Array<{ component: string; props?: Record<string, unknown> }> }) => void): Promise<string> {
  await page.goto('/admin/appearance/builder/checkout', { waitUntil: 'domcontentloaded' });
  const original = await page.locator('[data-layout-json]').inputValue();
  const token = await page.locator('form:has([data-layout-json]) input[name="_csrf_token"]').first().inputValue();
  const layout = JSON.parse(original);
  mutate(layout);
  const response = await page.request.post('/admin/appearance/builder/checkout', { form: { _csrf_token: token, layout_json: JSON.stringify(layout), builder_action: 'publish' } });
  expect(response.ok()).toBe(true);
  return original;
}

test('with the city search off the buyer types one address and the order is placed', async ({ page, browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Changes the checkout layout once.');
  test.setTimeout(300_000);
  page.setDefaultNavigationTimeout(120_000);
  await loginAdmin(page);
  const original = await publish(page, (layout) => {
    for (const block of layout.blocks) {
      if (block.component === 'checkout_shipping') block.props = { ...(block.props ?? {}), show_region: false, show_city_search: false };
    }
  });
  try {
    const shop = await (await browser.newContext({ baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8000' })).newPage();
    shop.setDefaultNavigationTimeout(120_000);
    await shop.goto('/catalog', { waitUntil: 'networkidle' });
    const card = shop.locator('[data-product-card]').filter({ has: shop.locator('form[data-card-add-to-cart]') }).first();
    const added = shop.waitForResponse((r) => r.url().endsWith('/cart/add') && r.request().method() === 'POST');
    await card.locator('form[data-card-add-to-cart] button[type="submit"]').click();
    expect((await (await added).json()).ok).toBe(true);
    await shop.goto('/checkout', { waitUntil: 'domcontentloaded' });
    const accept = shop.locator('[data-consent-accept-all]').first();
    if (await accept.isVisible().catch(() => false)) await accept.click();

    const remote = shop.locator('.ck-choice[data-carrier]:not([data-carrier="self_pickup"])').first();
    await expect(remote).toBeVisible();
    await remote.click();
    await expect(shop.locator('[data-delivery-remote]')).toBeVisible();
    await expect(shop.locator('.delivery-search')).toBeHidden();
    await expect(shop.locator('[data-delivery-region]')).toHaveCount(0);
    await expect(shop.locator('[data-delivery-fallback]')).toBeVisible();
    await expect(shop.locator('[data-delivery-manual-toggle]')).toBeHidden();

    await shop.locator('[data-checkout-form] input[name="name"]').fill('Один Адрес');
    await shop.locator('[data-checkout-form] input[name="phone"]').fill('+380501112266');
    await shop.locator('input[name="delivery_manual"]').fill('Дніпро, вул. Центральна 1');
    await shop.locator('input[name="payment_method"][value="cash_on_delivery"]').check();
    await Promise.all([shop.waitForURL(/\/checkout\/success\//, { timeout: 60_000 }), shop.locator('button.place-order').click()]);
  } finally {
    await publish(page, (layout) => {
      const saved = JSON.parse(original);
      layout.blocks = saved.blocks;
    });
  }
});
