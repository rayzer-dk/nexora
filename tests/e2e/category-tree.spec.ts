import { expect, test } from '@playwright/test';
import { expectNoServerError } from './helpers';

// Release 3.43.0: deep category tree of the demo catalogue — a parent lists the products of its whole branch,
// shows photo tiles of its subcategories and every level appears in the breadcrumbs.

test('a parent category lists its branch and shows subcategory tiles', async ({ page }) => {
  await page.goto('/electronics', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('.category-subcategories .reference-category').first()).toBeVisible();
  expect(await page.locator('.category-subcategories .reference-category').count()).toBeGreaterThanOrEqual(2);
  await expect(page.locator('.product-grid .product-card, .product-grid article').first()).toBeVisible();
  await expect(page.locator('.catalog-toolbar, .catalog-main').first()).not.toContainText('Товарів: 0');
});

test('a fourth-level category has the whole chain in its breadcrumbs and its tile leads there', async ({ page }) => {
  await page.goto('/smartphones', { waitUntil: 'domcontentloaded' });
  const tile = page.locator('.category-subcategories a.reference-category[href$="smartphones-apple"]');
  await expect(tile).toBeVisible();
  await Promise.all([page.waitForURL(/smartphones-apple$/), tile.click()]);
  const crumbs = page.locator('nav[aria-label] ol li, .breadcrumbs li, nav.breadcrumbs li');
  expect(await crumbs.count()).toBeGreaterThanOrEqual(6); // home, catalogue, 3 parents, the category
  await expect(page.locator('h1')).toContainText('Apple');
  await expect(page.locator('.catalog-main')).not.toContainText('Товарів: 0');
});

test('subcategory tiles do not break the mobile layout', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-mobile', 'Mobile layout runs on the mobile project.');
  await page.goto('/electronics', { waitUntil: 'domcontentloaded' });
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(1);
});

async function login(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')),
    page.locator('button[type="submit"]').click(),
  ]);
}

test('admin category list is a tree and the order can be changed in place', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating data runs once.');
  await login(page);
  await page.goto('/admin/catalog/categories', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('.admin-tree-cell').first()).toBeVisible();
  const depths = await page.locator('.admin-tree-cell').evaluateAll((els) => els.map((el) => Number((el as HTMLElement).style.getPropertyValue('--depth') || 0)));
  expect(Math.max(...depths)).toBeGreaterThanOrEqual(3); // four levels in the demo tree

  const box = page.locator('[data-quick-order]').first();
  const input = box.locator('input');
  const original = await input.inputValue();
  const url = await box.getAttribute('data-url');
  const changed = String(Number(original) + 7);
  const saved = page.waitForResponse((r) => r.url().includes('/quick-order') && r.request().method() === 'POST');
  await input.fill(changed);
  await input.press('Enter');
  expect((await saved).status()).toBe(200);
  try {
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.locator(`[data-quick-order][data-url="${url}"] input`)).toHaveValue(changed);
  } finally {
    const back = page.waitForResponse((r) => r.url().includes('/quick-order') && r.request().method() === 'POST');
    await page.locator(`[data-quick-order][data-url="${url}"] input`).fill(original);
    await page.locator(`[data-quick-order][data-url="${url}"] input`).press('Enter');
    expect((await back).status()).toBe(200);
  }
});
