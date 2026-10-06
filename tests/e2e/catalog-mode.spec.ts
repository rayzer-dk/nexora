import { expect, test } from '@playwright/test';

test('catalog mode shows products without cart, buy, notify, quick-order or checkout', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating global settings runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);

  const setMode = async (mode: string) => {
    await page.goto('/admin/system/site', { waitUntil: 'domcontentloaded' });
    await page.locator(`input[name="mode"][value="${mode}"]`).check({ force: true });
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form[data-site-mode-profiles] button[type="submit"]').first().click()]);
  };

  try {
    await setMode('catalog');
    await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-product-card]').first()).toBeVisible();
    await expect(page.locator('form[data-card-add-to-cart]')).toHaveCount(0);
    await expect(page.locator('[data-cart-trigger], a[href="/cart"]')).toHaveCount(0);

    const product = await page.locator('[data-product-card][data-track-id^="DEMO-"] a[href^="/"]').first().getAttribute('href');
    await page.goto(product!, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('h1').first()).toBeVisible();
    await expect(page.locator('form[data-buy-actions], form[action="/cart/add"], [data-quick-order], form[action*="stock-request"], form[action*="notify"]')).toHaveCount(0);
    await expect(page.locator('.product-price__current').first()).toBeVisible();

    for (const path of ['/cart', '/checkout']) {
      const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
      expect(response?.status() ?? 0, `${path} must not be served in catalog mode`).toBeGreaterThanOrEqual(300);
    }
  } finally {
    await setMode('hybrid');
  }
});
