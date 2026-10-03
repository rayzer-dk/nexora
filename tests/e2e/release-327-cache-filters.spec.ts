import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

// Release 3.27.0: the shop shows an admin change at once, the cache and maintenance page, the installed-modules page,
// and several values in one filter (shop brands, admin lists).

async function login(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')),
    page.locator('button[type="submit"]').click(),
  ]);
}

test.describe.configure({ mode: 'serial' });

test('the cache and maintenance page runs its actions and the modules page lists what is installed', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Uses the shared admin session once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  test.setTimeout(100000);
  await login(page);
  await page.goto('/admin/system/maintenance', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  expect(await page.locator('.admin-maintenance__action').count()).toBeGreaterThanOrEqual(7);

  for (const action of ['storefront', 'templates', 'images', 'warm']) {
    const form = page.locator(`form[action$="/admin/system/maintenance/${action}"]`);
    const answered = page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === `/admin/system/maintenance/${action}`, { timeout: 45000 });
    await form.locator('button[type="submit"]').click();
    await page.locator('[data-confirm-accept]').click({ timeout: 3000 }).catch(() => undefined);
    await answered;
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.admin-notice.is-success').first()).toBeAttached();
  }
  // the shop still works after everything was dropped
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('.catalog-card').first()).toBeVisible();

  await page.goto('/admin/system/modules', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  expect(await page.locator('table.admin-table').nth(2).locator('tbody tr').count()).toBeGreaterThanOrEqual(40);
  await expect(page.locator('table.admin-table').nth(1).locator('code', { hasText: 'uk-UA' })).toBeVisible();
});

test('an admin change shows on the shop at once, without waiting for the stored data to expire', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared demo data once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await login(page);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  await page.locator('tr').filter({ hasNotText: /Picked|E2E|Warm/i }).locator('a[href*="/admin/catalog/products/"][href$="/edit"]').first().click();
  await page.waitForURL(/\/edit/);
  const editUrl = page.url();
  const slug = await page.locator('#product-form input[name="slug"]').inputValue();
  const original = await page.locator('#product-form input[name="name"]').inputValue();

  await page.goto(`/${slug}`, { waitUntil: 'domcontentloaded' }); // fills the stored data with the old name
  const renamed = `${original} ${String(Date.now()).slice(-5)}`;
  await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
  await page.locator('#product-form input[name="name"]').fill(renamed);
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && /\/edit$/.test(new URL(r.url()).pathname)),
    page.locator('[data-primary-submit]').first().click(),
  ]);
  await page.waitForLoadState('domcontentloaded');
  await page.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('h1').first()).toContainText(renamed, { timeout: 2000 });

  await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
  await page.locator('#product-form input[name="name"]').fill(original);
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && /\/edit$/.test(new URL(r.url()).pathname)),
    page.locator('[data-primary-submit]').first().click(),
  ]);
});

test('the shop filter takes several brands, and the old single brand link still works', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Desktop filter panel.');
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const boxes = page.locator('#catalog-filters input[name="brand[]"]');
  const count = await boxes.count();
  test.skip(count < 2, 'The demo catalogue needs at least two brands.');
  const first = await boxes.nth(0).getAttribute('value');
  const second = await boxes.nth(1).getAttribute('value');
  await boxes.nth(0).check();
  await boxes.nth(1).check();
  await page.waitForURL((url) => url.searchParams.getAll('brand[]').length === 2);
  await expectNoServerError(page);
  expect(new URL(page.url()).searchParams.getAll('brand[]').sort()).toEqual([first, second].sort());
  await expect(page.locator('#catalog-filters input[name="brand[]"]:checked')).toHaveCount(2);
  expect(await page.locator('.catalog-card').count()).toBeGreaterThan(0);
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);

  await page.goto(`/catalog?brand=${first}`, { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator(`#catalog-filters input[name="brand[]"][value="${first}"]`)).toBeChecked();
});

test('admin lists take several values in one filter', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Uses the shared admin session once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await login(page);
  await page.goto('/admin/orders', { waitUntil: 'domcontentloaded' });
  const box = page.locator('[data-multiselect]').first();
  await box.locator('summary').click();
  const options = box.locator('input[type="checkbox"]');
  expect(await options.count()).toBeGreaterThanOrEqual(3);
  await options.nth(0).check();
  await options.nth(1).check();
  await expect(box.locator('[data-multiselect-text]')).toContainText('2');
  await box.locator('summary').click(); // close the list: the date fields moved the Apply button under the open panel
  await Promise.all([page.waitForURL((url) => url.searchParams.getAll('status[]').length === 2), page.locator('.admin-filters button[type="submit"]').click()]);
  await expectNoServerError(page);
  await expect(page.locator('[data-multiselect]').first().locator('input:checked')).toHaveCount(2);

  for (const path of ['/admin/catalog/products?status[]=draft&status[]=published', '/admin/content/blog?status[]=draft&status[]=published', '/admin/content/pages?status[]=draft&status[]=published']) {
    await page.goto(path, { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    await expect(page.locator('[data-multiselect]').first().locator('input:checked')).toHaveCount(2);
  }
  await page.goto('/admin/catalog/products?status[]=draft&status[]=published&brand[]=1&category[]=1', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
});
