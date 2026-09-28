import { expect, test } from '@playwright/test';
import { expectNoHorizontalOverflow, expectNoServerError } from './helpers';

test('admin login page is usable', async ({ page }) => {
  const response = await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  expect(response?.status() ?? 0).toBeLessThan(500);
  await expectNoServerError(page);
  await expect(page.locator('input[name="_username"]')).toBeVisible();
  await expect(page.locator('input[name="_password"]')).toBeVisible();
  await expect(page.locator('button[type="submit"]')).toBeVisible();
  await expectNoHorizontalOverflow(page);
});

test('admin authentication works when CI credentials are provided', async ({ page }) => {
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'E2E admin credentials are not configured');
  await page.goto('/admin/login');
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL(/\/admin(?:\/|$)/),
    page.locator('button[type="submit"]').click(),
  ]);
  await expectNoServerError(page);
});
