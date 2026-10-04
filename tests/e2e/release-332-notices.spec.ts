import { expect, test } from '@playwright/test';

// Release 3.43.0: inline notices and errors can be closed.

test('login error notice has a working close button', async ({ page }) => {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill('nobody@example.test');
  await page.locator('input[name="_password"]').fill('wrong-password-123');
  await page.locator('button[type="submit"]').click();
  const notice = page.locator('.admin-notice').first();
  await expect(notice).toBeVisible();
  const close = notice.locator('.notice-close');
  await expect(close).toBeVisible();
  await close.click();
  await expect(notice).toBeHidden();
});
