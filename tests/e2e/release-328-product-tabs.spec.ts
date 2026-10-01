import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

// Release 3.28.0: the product form is split into tabs; only one group of fields is shown at a time.

async function login(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')),
    page.locator('button[type="submit"]').click(),
  ]);
}

test('the product form shows one tab at a time and opens the tab of an invalid field', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Uses the shared admin session once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await login(page);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  await page.locator('tr').filter({ hasNotText: /Picked|E2E|Warm/i }).locator('a[href*="/admin/catalog/products/"][href$="/edit"]').first().click();
  await page.waitForURL(/\/edit/);
  await expectNoServerError(page);

  const tabs = page.locator('.admin-tabs .admin-tab');
  expect(await tabs.count()).toBeGreaterThanOrEqual(5);
  await tabs.first().click();
  await expect(page.locator('#product-form input[name="name"]')).toBeVisible();
  await expect(page.locator('#product-form [data-media-sortable], #product-form input[name="video_url"]').first()).toBeHidden();

  await tabs.filter({ hasText: /Photos|Фото/ }).click();
  await expect(page.locator('#product-form input[name="video_url"]')).toBeVisible();
  await expect(page.locator('#product-form input[name="name"]')).toBeHidden();
  // the main save button stays visible on every tab
  await expect(page.locator('[data-primary-submit]').first()).toBeVisible();

  // an invalid field of another tab opens that tab on save
  await tabs.first().click();
  await page.locator('#product-form input[name="name"]').fill('');
  await tabs.filter({ hasText: /Photos|Фото/ }).click();
  await page.locator('[data-primary-submit]').first().click();
  await expect(page.locator('#product-form input[name="name"]')).toBeVisible();

  // a link to a section opens its tab
  await page.goto(`${page.url().replace(/#.*$/, '')}#variants`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#variants')).toBeVisible();
});
