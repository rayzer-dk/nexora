import { expect, test } from '@playwright/test';

test('a volume price shows on the product page and applies in the cart from the set quantity', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating data runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);

  const sku = 'DEMO-042';
  await page.goto(`/admin/catalog/products?per_page=200`, { waitUntil: 'domcontentloaded' });
  const editHref = await page.locator('table.admin-table tbody tr').filter({ hasText: sku }).first().locator('a[href$="/edit"]').first().getAttribute('href');
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

  const slug = await save({ price_tiers: '2=0.10', cost_price: '0.05' });
  try {
    await expect(page.locator('input[name="price_tiers"]')).toHaveValue('2=0.10');
    await expect(page.locator('input[name="cost_price"]')).toHaveValue('0.05');
    await page.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.product-tiers__list li').first()).toContainText('0,10');

    const form = page.locator('form[data-buy-actions]').first();
    await form.locator('input[data-qty-input]').evaluate((el: HTMLInputElement) => { el.value = '2'; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); });
    await Promise.all([
      page.waitForResponse((r) => r.url().endsWith('/cart/add') && r.request().method() === 'POST'),
      form.locator('button[type="submit"]').first().click(),
    ]);
    await page.goto('/cart', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('main')).toContainText('0,10');
  } finally {
    await save({ price_tiers: '', cost_price: '' });
  }
});
