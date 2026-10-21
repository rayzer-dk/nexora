import { expect, test, type Page } from '@playwright/test';
import { loginAdmin } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

async function expectNoHorizontalScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(1);
}

test('module journal filters, exports CSV and opens diagnostics', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Read-only admin flow runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  await page.goto('/admin/system/extensions', { waitUntil: 'domcontentloaded' });
  await expect(page.getByText('Extension audit trail')).toHaveCount(0);

  await page.locator('a[href$="/admin/system/extensions/journal"]').first().click();
  await expect(page).toHaveURL(/\/admin\/system\/extensions\/journal/);
  await expect(page.locator('[data-extension-journal] select[name="code"]')).toBeVisible();
  await expect(page.locator('[data-extension-journal] select[name="action"]')).toBeVisible();

  const csv = await page.request.get('/admin/system/extensions/journal.csv');
  expect(csv.status()).toBe(200);
  expect(csv.headers()['content-type']).toContain('text/csv');
  expect(csv.headers()['content-disposition']).toContain('attachment');
  expect((await csv.text()).split('\n')[0]).toContain('module');

  // Unknown filter values are ignored, not echoed or errored.
  const unknown = await page.request.get('/admin/system/extensions/journal?code=%27%3Cx%3E&action=nope');
  expect(unknown.status()).toBe(200);

  const diagnostics = page.locator('a[href$="/diagnostics"]').first();
  if (await diagnostics.count()) {
    await diagnostics.click();
    await expect(page.locator('main table, .admin-panel table').first()).toBeVisible();
    for (const scheme of ['light', 'dark'] as const) {
      await page.emulateMedia({ colorScheme: scheme });
      await page.setViewportSize({ width: 390, height: 800 });
      await expectNoHorizontalScroll(page);
      await page.setViewportSize({ width: 1440, height: 1000 });
    }
  }

  await page.goto('/admin/system/extensions/journal', { waitUntil: 'domcontentloaded' });
  for (const scheme of ['light', 'dark'] as const) {
    await page.emulateMedia({ colorScheme: scheme });
    await page.setViewportSize({ width: 390, height: 800 });
    await expectNoHorizontalScroll(page);
    await page.setViewportSize({ width: 1440, height: 1000 });
  }
});
