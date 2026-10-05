import { expect, test } from '@playwright/test';
import { expectNoServerError } from './helpers';

test('Telegram alert events can be chosen in the notification settings', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings run once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);

  const save = async (review: boolean) => {
    await page.goto('/admin/commerce/notification-channels', { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    const form = page.locator('form[action$="/telegram-alerts"]');
    await form.locator('input[name="events[review]"]').setChecked(review);
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
  };
  await save(true);
  await page.goto('/admin/commerce/notification-channels', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('form[action$="/telegram-alerts"] input[name="events[review]"]')).toBeChecked();
  await expect(page.locator('form[action$="/telegram-alerts"] input[name="events[order_created]"]')).toBeChecked();
  await save(false);
  await page.goto('/admin/commerce/notification-channels', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('form[action$="/telegram-alerts"] input[name="events[review]"]')).not.toBeChecked();
});
