import { expect, test } from '@playwright/test';
import { expectNoServerError } from './helpers';

test('bot protection page saves the scan-protection and reputation settings', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings run once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);

  await page.goto('/admin/system/bots', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await page.locator('input[name="probe_max"]').fill('37');
  await page.locator('input[name="rep_abuse_min"]').fill('65');
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form[data-dirty-guard] button[type="submit"]').click()]);
  await expectNoServerError(page);
  await expect(page.locator('input[name="probe_max"]')).toHaveValue('37');
  await expect(page.locator('input[name="rep_abuse_min"]')).toHaveValue('65');
  // restore the defaults
  await page.locator('input[name="probe_max"]').fill('20');
  await page.locator('input[name="rep_abuse_min"]').fill('50');
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form[data-dirty-guard] button[type="submit"]').click()]);
});
