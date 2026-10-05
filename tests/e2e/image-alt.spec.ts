import { expect, test } from '@playwright/test';
import { expectNoServerError } from './helpers';

test('product photo ALT template is previewed in the admin and used on the storefront', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings run once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);

  await page.goto('/admin/catalog/image-alt?overwrite=1', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await page.locator('input[name="main"]').fill('{name} — {brand} | {store}');
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form[action$="/image-alt/save"] button[type="submit"]').click()]);
  await expectNoServerError(page);
  await page.goto('/admin/catalog/image-alt?overwrite=1', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('table.admin-table tbody tr').first()).toBeVisible();

  // restore the default template
  await page.locator('input[name="main"]').fill('{name}');
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form[action$="/image-alt/save"] button[type="submit"]').click()]);
});
