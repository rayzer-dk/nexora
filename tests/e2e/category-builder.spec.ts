import { expect, test, type Page } from '@playwright/test';

test.describe.configure({ retries: 0, mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/|$)/), page.locator('button[type="submit"]').click()]);
}

async function publish(page: Page, layoutJson: string): Promise<void> {
  await page.goto('/admin/appearance/builder/category', { waitUntil: 'domcontentloaded' });
  const token = await page.locator('#builder-form input[name="_csrf_token"]').inputValue();
  const response = await page.request.post('/admin/appearance/builder/category', {
    maxRedirects: 0,
    form: { _csrf_token: token, layout_json: layoutJson, builder_action: 'publish' },
  });
  expect(response.status()).toBeGreaterThanOrEqual(300);
  expect(response.status()).toBeLessThan(400);
}

test('Category Builder changes the real category page and restores cleanly', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating builder contract runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const categoryUrl = await page.locator('.category-chips a').first().getAttribute('href');
  test.skip(!categoryUrl, 'The demo store has no category.');

  await loginAdmin(page);
  await page.goto('/admin/appearance/builder/category', { waitUntil: 'domcontentloaded' });
  const originalRaw = await page.locator('textarea[name="layout_json"]').inputValue();
  const layout = JSON.parse(originalRaw);
  const components = (layout.blocks as { component: string }[]).map((row) => row.component);
  expect(components).toEqual(['category_heading', 'category_filters', 'category_toolbar', 'category_recommended', 'category_grid', 'category_description']);

  // filters off, heading off, products above the sorting bar, the grid itself cannot be switched off
  const byName = (name: string) => layout.blocks.find((row: { component: string }) => row.component === name);
  byName('category_filters').enabled = false;
  byName('category_heading').enabled = false;
  byName('category_grid').enabled = false;
  layout.blocks.splice(2, 0, layout.blocks.splice(4, 1)[0]);
  await publish(page, JSON.stringify(layout));

  try {
    const response = await page.goto(categoryUrl!, { waitUntil: 'domcontentloaded' });
    expect(response?.status()).toBe(200);
    await expect(page.locator('#catalog-filters')).toHaveCount(0);
    await expect(page.locator('.catalog-layout--no-filters')).toHaveCount(1);
    await expect(page.locator('.catalog-heading')).toHaveCount(0);
    await expect(page.locator('h1')).toHaveCount(1); // the page keeps its title for search engines and screen readers
    await expect(page.locator('.catalog-main .product-grid, .catalog-main .empty-state').first()).toBeVisible(); // the product area stayed although the editor switched it off
    const gridFirst = await page.evaluate(() => {
      const grid = document.querySelector('.catalog-main > .product-grid, .catalog-main > .empty-state');
      const toolbar = document.querySelector('.catalog-main .cf-toolbar');
      return !!grid && !!toolbar && Boolean(grid.compareDocumentPosition(toolbar) & Node.DOCUMENT_POSITION_FOLLOWING);
    });
    expect(gridFirst).toBe(true);
  } finally {
    await publish(page, originalRaw);
  }

  await page.goto(categoryUrl!, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#catalog-filters')).toHaveCount(1);
  await expect(page.locator('.catalog-heading')).toHaveCount(1);
});
