import { expect, test } from '@playwright/test';

test('with the cart off, compare, sticky buy bar and related products show no stock text or buy buttons', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating global settings runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  test.setTimeout(300_000);

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
    const compareButtons = page.locator('.catalog-card__compare button');
    await compareButtons.first().click();
    await compareButtons.nth(1).click();
    await page.goto('/compare', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.compare-table')).toBeVisible();
    await expect(page.locator('.compare-table')).not.toContainText(/немає в наявності|в наявності|нет в наличии/i);
    await expect(page.locator('.compare-table button:not([type="submit"]), .compare-table [data-card-add-to-cart]')).toHaveCount(0);

    const products = await page.locator('[data-product-card] a.catalog-card__media').evaluateAll((els) => els.slice(0, 6).map((el) => (el as HTMLAnchorElement).getAttribute('href') ?? ''));
    for (const href of products) {
      await page.goto(href, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('.mobile-buy-bar')).toHaveCount(0);
      await expect(page.locator('.related-card [data-card-add-to-cart]')).toHaveCount(0);
      await expect(page.locator('.related-card small')).toHaveCount(0);
      await expect(page.locator('.purchase-unavailable')).toHaveCount(0);
    }
  } finally {
    await setMode('hybrid');
  }
});

test('with the cart off, a product block bound to product.availability prints nothing', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating global settings runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  test.setTimeout(300_000);

  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);

  const setMode = async (mode: string) => {
    await page.goto('/admin/system/site', { waitUntil: 'domcontentloaded' });
    await page.locator(`input[name="mode"][value="${mode}"]`).check({ force: true });
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form[data-site-mode-profiles] button[type="submit"]').first().click()]);
  };
  const publish = async (layoutJson: string) => {
    await page.goto('/admin/appearance/builder/product', { waitUntil: 'domcontentloaded' });
    const token = await page.locator('#builder-form input[name="_csrf_token"]').inputValue();
    const response = await page.request.post('/admin/appearance/builder/product', { maxRedirects: 0, form: { _csrf_token: token, layout_json: layoutJson, builder_action: 'publish' } });
    expect(response.status()).toBeGreaterThanOrEqual(300);
    expect(response.status()).toBeLessThan(400);
  };

  await page.goto('/admin/appearance/builder/product', { waitUntil: 'domcontentloaded' });
  const originalRaw = await page.locator('textarea[name="layout_json"]').inputValue();
  const layout = JSON.parse(originalRaw);
  const template = layout.blocks?.find((row: { component?: string }) => row.component === 'product_description');
  test.skip(!template, 'Current product layout has no block to clone.');
  layout.blocks.push({ ...template, id: 'availability-probe', component: 'info_card', enabled: true, props: { data_source: 'product.availability', title: 'Availability probe' } });

  try {
    await setMode('catalog');
    await publish(JSON.stringify(layout));
    await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
    const href = await page.locator('[data-product-card] a.catalog-card__media').first().getAttribute('href');
    await page.goto(href!, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.product-info-card')).toContainText('Availability probe');
    await expect(page.locator('.product-info-card')).not.toContainText(/наявності|наличии|in stock/i);
  } finally {
    await setMode('hybrid');
    await publish(originalRaw);
  }
});
