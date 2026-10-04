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

// Release 3.47.0

test('the catalogue pages without a full reload and keeps working links', async ({ page }) => {
  await page.goto('/catalog', { waitUntil: 'networkidle' });
  await page.evaluate(() => { (window as unknown as { __kept: number }).__kept = 1; });
  const firstBefore = await page.locator('[data-product-card] h2 a').first().textContent();
  await page.locator('nav.pager a').last().click();
  await expect(page).toHaveURL(/page=2/);
  await expect.poll(async () => page.locator('[data-product-card] h2 a').first().textContent()).not.toBe(firstBefore);
  expect(await page.evaluate(() => (window as unknown as { __kept?: number }).__kept)).toBe(1);
  await page.goBack();
  await expect(page).not.toHaveURL(/page=2/);
});

test('the share menu has coloured network icons and a copy button with text', async ({ page }) => {
  await page.goto('/iphone-5s', { waitUntil: 'domcontentloaded' });
  await page.locator('.share-pop > summary').first().click();
  await expect(page.locator('.share-pop__net')).toHaveCount(5);
  await expect(page.locator('.share-pop__net').first()).not.toHaveText(/\S/);
  await expect(page.locator('.share-pop__copy')).toContainText(/\S/);
  await expect(page.locator('.buy-actions__unit').first()).toBeVisible();
});

test('gift card and bonus fields have apply buttons and the order summary stays in view', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Desktop layout.');
  await page.goto('/iphone-5s', { waitUntil: 'domcontentloaded' });
  await page.locator('[data-primary-buy]').first().click();
  await page.waitForTimeout(800);
  await page.goto('/checkout', { waitUntil: 'domcontentloaded' });
  await page.locator('[data-checkout-block="gift"] summary').click();
  await expect(page.locator('[data-reward-apply]').first()).toBeVisible();
  await page.evaluate(() => window.scrollTo(0, 700));
  await page.waitForTimeout(300);
  const top = await page.locator('.order-summary').evaluate((node) => node.getBoundingClientRect().top);
  expect(top).toBeGreaterThanOrEqual(0);
  expect(top).toBeLessThan(200);
});

test('categories and blog articles have a quick status switch; the demo page opens', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Admin page runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await loginAdmin(page);
  await page.goto('/admin/catalog/categories', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-quick-status]').first()).toBeAttached();
  await page.goto('/admin/content/blog', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-quick-status]').first()).toBeAttached();
  await page.goto('/admin/content/blog/categories', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-quick-status]').first()).toBeAttached();
  const demo = await page.goto('/admin/system/demo', { waitUntil: 'domcontentloaded' });
  expect(demo?.status()).toBe(200);
  await expectNoServerError(page);
});

// Release 3.48.0

test('the 404 page is big, has search and an illustration', async ({ page }) => {
  const response = await page.goto('/this-page-does-not-exist', { waitUntil: 'domcontentloaded' });
  expect(response?.status()).toBe(404);
  await expect(page.locator('[data-error-page="404"] .error-page__code')).toBeVisible();
  await expect(page.locator('.error-page__search input')).toBeVisible();
  await expect(page.locator('.error-page__art')).toBeVisible();
});

test('admin pages use tabs instead of long ribbons and show the new header counter', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Admin pages run once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await loginAdmin(page);
  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  expect(await page.locator('.admin-tabs .admin-tab').count()).toBeGreaterThanOrEqual(6);
  await page.goto('/admin/catalog/metadata', { waitUntil: 'domcontentloaded' });
  expect(await page.locator('.admin-tabs .admin-tab').count()).toBeGreaterThanOrEqual(2);
  await page.goto('/admin/commerce/marketing-automation', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.admin-tabs .admin-tab').first()).toBeVisible();
  await expect(page.locator('.admin-attention')).toBeVisible();
  await page.goto('/admin/catalog/badges', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-tone-field]').first()).toBeVisible();
  await expect(page.locator('[data-tone-hex]').first()).toBeVisible();
  await page.goto('/admin/appearance/support-chat', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('input[name="load_delay_seconds"]')).toBeVisible();
  await page.goto('/admin', { waitUntil: 'domcontentloaded' });
  const quick = await page.locator('.admin-quick-grid').first().evaluate((node) => node.getBoundingClientRect().top);
  const kpis = await page.locator('.dash-kpis').first().evaluate((node) => node.getBoundingClientRect().top);
  expect(quick).toBeLessThan(kpis);
});

test('a product opens in a language without a translation, the file manager makes folders and rows open on click', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Admin pages run once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await loginAdmin(page);

  // A click on a row of the product list opens the product.
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  const row = page.locator('table.admin-table tbody tr').first();
  await row.locator('td').nth(2).click({ position: { x: 5, y: 5 } });
  await page.waitForURL(/\/admin\/catalog\/products\/[0-9a-f-]{36}\/edit/);
  const editUrl = page.url().split('?')[0];

  // A language that has no translation of this product opens an empty form instead of an error.
  const response = await page.goto(`${editUrl}?locale=de-DE`, { waitUntil: 'domcontentloaded' });
  expect(response?.status()).toBe(200);

  // The file manager: icon toolbar, a new folder, the folder is remembered.
  await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
  await page.locator('.admin-tab[data-tab-target="media"]').click();
  await page.locator('[data-media-pick="multiple"]').click();
  const dialog = page.locator('dialog.mc-picker');
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('.mc-picker__tool')).toHaveCount(5);
  await dialog.locator('.mc-picker__bar .mc-picker__tool').nth(2).click();
  const name = `E2E ${Date.now()}`;
  await dialog.locator('.mc-picker__newfolder input').fill(name);
  await dialog.locator('.mc-picker__newfolder button[type="submit"]').click();
  await expect(dialog.locator('.mc-picker__path')).toContainText(name);
  await dialog.locator('.admin-modal__close').click();
  await page.locator('[data-media-pick="multiple"]').click();
  await expect(page.locator('dialog.mc-picker .mc-picker__path')).toContainText(name);
});
