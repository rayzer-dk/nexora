import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

// Release 3.46.0: log viewer, category description position, selectable product type, currency switcher, wider storefront.

test.describe.configure({ mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

test('the admin can read logs and errors', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Admin page runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await loginAdmin(page);
  const response = await page.goto('/admin/system/logs', { waitUntil: 'domcontentloaded' });
  expect(response?.status()).toBe(200);
  await expectNoServerError(page);
  await expect(page.locator('main h1').first()).toBeVisible();
});

test('the description position of all categories is a global setting', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating setting runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await loginAdmin(page);
  await page.goto('/admin/catalog/categories', { waitUntil: 'domcontentloaded' });
  const select = page.locator('.admin-category-url select[name="description_position"]');
  await expect(select).toBeVisible();
  const original = await select.inputValue();
  await select.selectOption(original === 'top' ? 'bottom' : 'top');
  await Promise.all([page.waitForURL(/\/admin\/catalog\/categories/), page.locator('.admin-category-url button[type="submit"]').click()]);
  await expect(page.locator('.admin-category-url select[name="description_position"]')).toHaveValue(original === 'top' ? 'bottom' : 'top');
  await page.locator('.admin-category-url select[name="description_position"]').selectOption(original);
  await Promise.all([page.waitForURL(/\/admin\/catalog\/categories/), page.locator('.admin-category-url button[type="submit"]').click()]);
});

test('the product type can be chosen in the product form', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Admin form runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await loginAdmin(page);
  await page.goto('/admin/catalog/products/new', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const type = page.locator('select[name="product_type"]');
  await expect(type).toBeVisible();
  await type.selectOption('digital');
  await expect(type).toHaveValue('digital');
});

test('the storefront offers a currency switcher and the forum link', async ({ page }) => {
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('[data-currency-switch]').first()).toBeAttached();
  await expect(page.locator('a[href="/forum"]').first()).toBeAttached();
});
