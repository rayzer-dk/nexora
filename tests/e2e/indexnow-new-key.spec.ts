import { expect, test } from '@playwright/test';
import { expectNoServerError, loginAdmin } from './helpers';

test.beforeEach(async ({}, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared CI data once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');
});

test('IndexNow "New key" asks for confirmation and replaces the key file', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/system/seo-indexnow', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const keyLink = page.locator('.admin-form-wide a[href$=".txt"] code');
  const before = await keyLink.innerText();

  await page.locator('[data-indexnow-new-key]').click();
  await expect(page.locator('[data-admin-confirm] [data-confirm-accept]')).toBeVisible();
  expect(await keyLink.innerText()).toBe(before); // nothing changes before the confirmation
  await page.locator('[data-admin-confirm] [data-confirm-accept]').click();

  await expect(keyLink).not.toHaveText(before);
  expect(await keyLink.innerText()).toMatch(/\/[a-f0-9]{32}\.txt$/);
});
