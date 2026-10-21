import { expect, test } from '@playwright/test';
import { expectNoServerError, loginAdmin } from './helpers';

// Release 3.61.0: per-channel feed rules and SMS statistics with retry.

test.describe.configure({ mode: 'serial' });

test('a feed channel keeps its own rules', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await loginAdmin(page);
  await page.goto('/admin/commerce/feeds', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const form = page.locator('form[action$="/admin/commerce/feeds/csv/rules"]');
  await expect(form).toBeAttached();
  await form.locator('input[name="markup_percent"]').evaluate((el: HTMLInputElement) => { el.value = '7.5'; });
  await Promise.all([page.waitForURL(/\/admin\/commerce\/feeds/), form.locator('button[type="submit"]').evaluate((el: HTMLElement) => el.click())]);
  await expectNoServerError(page);
  await expect(page.locator('form[action$="/admin/commerce/feeds/csv/rules"] input[name="markup_percent"]')).toHaveValue('7.5');
  await page.locator('form[action$="/admin/commerce/feeds/csv/rules"] input[name="markup_percent"]').evaluate((el: HTMLInputElement) => { el.value = '0'; });
  await Promise.all([page.waitForURL(/\/admin\/commerce\/feeds/), page.locator('form[action$="/admin/commerce/feeds/csv/rules"] button[type="submit"]').evaluate((el: HTMLElement) => el.click())]);
});

test('the SMS page shows statistics', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await loginAdmin(page);
  await page.goto('/admin/commerce/sms', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('.admin-stats article')).toHaveCount(3);
});
