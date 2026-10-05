import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

// Release 3.59.0: missing pages come with a suggested target; the dashboard lists the work that needs attention.

test.describe.configure({ mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

test('a missing address is counted and the shop suggests the page it most likely meant', async ({ page, request }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  const miss = await request.get('/iphone5s.html', { maxRedirects: 0 });
  expect(miss.status()).toBe(404);
  await loginAdmin(page);
  await page.goto('/admin/system/seo-custom-redirects', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const row = page.locator('[data-tab="notfound"] tbody tr', { hasText: '/iphone5s.html' }).first();
  await expect(row).toBeAttached();
  await expect(row.locator('form[action$="/save"] button')).toContainText('iphone-5s');
});

test('the dashboard shows the new "needs attention" items', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await loginAdmin(page);
  await page.goto('/admin', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('a[href="/admin/system/seo-custom-redirects"]').first()).toBeAttached();
});
