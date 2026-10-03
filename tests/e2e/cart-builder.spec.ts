import { expect, test, type Page } from '@playwright/test';

test.describe.configure({ retries: 0, mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

async function publish(page: Page, layoutJson: string): Promise<void> {
  await page.goto('/admin/appearance/builder/cart', { waitUntil: 'domcontentloaded' });
  const token = await page.locator('#builder-form input[name="_csrf_token"]').inputValue();
  const response = await page.request.post('/admin/appearance/builder/cart', {
    maxRedirects: 0,
    form: { _csrf_token: token, layout_json: layoutJson, builder_action: 'publish' },
  });
  expect(response.status()).toBeGreaterThanOrEqual(300);
  expect(response.status()).toBeLessThan(400);
}

test('Cart Builder changes the real cart page and restores cleanly', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating builder contract runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  await page.goto('/admin/appearance/builder/cart', { waitUntil: 'domcontentloaded' });
  const originalRaw = await page.locator('textarea[name="layout_json"]').inputValue();
  const layout = JSON.parse(originalRaw);
  expect((layout.blocks as { component: string }[]).map((row) => row.component)).toEqual(['cart_heading', 'cart_lines', 'cart_summary', 'cart_saved', 'cart_recent']);

  // heading off, the lines and the summary cannot be removed by the editor
  const byName = (name: string) => layout.blocks.find((row: { component: string }) => row.component === name);
  byName('cart_heading').enabled = false;
  byName('cart_lines').enabled = false;
  byName('cart_summary').enabled = false;
  await publish(page, JSON.stringify(layout));

  try {
    const shop = await page.context().newPage();
    const response = await shop.goto('/cart', { waitUntil: 'domcontentloaded' });
    expect(response?.status()).toBe(200);
    await expect(shop.locator('.catalog-heading')).toHaveCount(0);
    await expect(shop.locator('h1')).toHaveCount(1); // the title stays for screen readers
    await expect(shop.locator('[data-cart-content]')).toHaveCount(1);
    await expect(shop.locator('[data-cart-empty]')).toHaveCount(1);
    await shop.close();
  } finally {
    await publish(page, originalRaw);
  }

  await page.goto('/cart', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.catalog-heading')).toHaveCount(1);
});
