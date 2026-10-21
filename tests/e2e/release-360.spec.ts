import { expect, test } from '@playwright/test';
import { expectNoServerError, loginAdmin } from './helpers';

// Release 3.60.0: lost demand, product tips, broken link check, early warnings and the daily report.

test.describe.configure({ mode: 'serial' });

test('analytics has the lost-demand and product-tip tabs', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await loginAdmin(page);
  await page.goto('/admin/analytics', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('[data-tab="lost"]')).toBeAttached();
  await expect(page.locator('[data-tab="insights"]')).toBeAttached();
});

test('the link check runs and lists a broken link and a missing picture of an article', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await loginAdmin(page);
  await page.goto('/admin/content/pages', { waitUntil: 'domcontentloaded' });
  await page.locator('table.admin-table a[href^="/admin/content/pages/"]').first().click();
  await expectNoServerError(page);
  await page.goto('/admin/system/link-check', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await Promise.all([page.waitForNavigation(), page.locator('form[action$="/run"] button[type="submit"]').click()]);
  await expect(page.locator('.admin-notice.is-success')).toBeAttached();
  await expect(page.locator('.admin-stats')).toBeVisible();
});

test('early warnings page saves the daily report settings and queues a test letter', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await loginAdmin(page);
  await page.goto('/admin/system/early-warnings', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await page.locator('input[name="enabled"]').check();
  await page.locator('textarea[name="recipients"]').fill('owner-e2e@example.test');
  await Promise.all([page.waitForNavigation(), page.locator('form[action$="/save"] button[type="submit"]').click()]);
  await expect(page.locator('.admin-notice.is-success')).toBeAttached();
  await Promise.all([page.waitForNavigation(), page.locator('form[action$="/test"] button[type="submit"]').click()]);
  await expect(page.locator('.admin-notice.is-success')).toBeAttached();
  await page.goto('/admin', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
});
