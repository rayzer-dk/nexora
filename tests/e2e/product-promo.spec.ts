import { expect, test } from '@playwright/test';

test('a dated sale price, tags and an own canonical set in the product editor reach the storefront', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating data runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);

  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  const editHref = await page.locator('table.admin-table tbody tr').filter({ has: page.locator('[data-quick-status] input:checked') }).first().locator('a[href$="/edit"]').first().getAttribute('href');
  expect(editHref).toBeTruthy();

  const save = async (fill: Record<string, string>): Promise<string> => {
    await page.goto(editHref!, { waitUntil: 'domcontentloaded' });
    for (const [name, value] of Object.entries(fill)) {
      await page.locator(`#product-form [name="${name}"]`).evaluate((el: HTMLInputElement, v: string) => { el.value = v; }, value);
    }
    await Promise.all([
      page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/edit')),
      page.locator('#product-form').evaluate((form: HTMLFormElement) => form.requestSubmit()),
    ]);
    await page.waitForLoadState('load');
    await page.goto(editHref!, { waitUntil: 'domcontentloaded' });
    return page.locator('input[name="slug"]').inputValue();
  };

  const stamp = Date.now();
  const canonical = `https://example.test/canonical-${stamp}`;
  const slug = await save({ sale_price: '1.00', tags: `promo${stamp}, second`, canonical_url: canonical, 'custom_label[0]': 'sale', related_skus: 'DEMO-027' });
  try {
    await expect(page.locator('input[name="sale_price"]')).toHaveValue('1.00');
    await expect(page.locator('input[name="related_skus"]')).toHaveValue(/DEMO-027/);
    await page.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.product-price__current').first()).toContainText('1,00');
    await expect(page.locator('.product-price__old').first()).toBeVisible();
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', canonical);
    await expect(page.locator('.product-tags__item', { hasText: `promo${stamp}` })).toBeVisible();
  } finally {
    await save({ sale_price: '', tags: '', canonical_url: '', 'custom_label[0]': '', related_skus: '' });
  }
  await page.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.product-price__current').first()).not.toContainText('1,00 ');
  await expect(page.locator('link[rel="canonical"]')).not.toHaveAttribute('href', canonical);
});
