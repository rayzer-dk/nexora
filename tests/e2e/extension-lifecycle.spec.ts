import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0 });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL(/\/admin(?:\/|$)/),
    page.locator('button[type="submit"]').click(),
  ]);
  await expectNoServerError(page);
}

test('extension package can be installed, activated and disabled', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating extension audit runs once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');

  await loginAdmin(page);
  await page.goto('/admin/system/extensions', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);

  const packageInput = page.locator('input[name="extension_package"]');
  await packageInput.setInputFiles(path.resolve('var/e2e-extension.zip'));
  const uploadResponsePromise = page.waitForResponse((response) =>
    response.url().includes('/admin/system/extensions') && response.request().method() === 'POST'
  );
  await page.locator('form.admin-extension-upload button[type="submit"]').click();
  const uploadResponse = await uploadResponsePromise;
  expect(uploadResponse.status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expectNoServerError(page);

  const extensionRow = page.locator('table.admin-table tbody tr').filter({ hasText: 'Nexora E2E QA Module' });
  await expect(extensionRow).toBeVisible();
  await expect(extensionRow).toContainText('staged');

  const activateForm = extensionRow.locator('form[action$="/activate"]');
  const activateResponsePromise = page.waitForResponse((response) =>
    response.url().includes('/activate') && response.request().method() === 'POST'
  );
  await activateForm.locator('button[type="submit"]').click();
  await expect(page.locator('[data-admin-confirm]')).toBeVisible();
  await page.locator('[data-admin-confirm] [data-confirm-accept]').click();
  const activateResponse = await activateResponsePromise;
  expect(activateResponse.status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  const activeRow = page.locator('table.admin-table tbody tr').filter({ hasText: 'Nexora E2E QA Module' });
  await expect(activeRow).toContainText('active');

  const disableForm = activeRow.locator('form[action$="/disable"]');
  const disableResponsePromise = page.waitForResponse((response) =>
    response.url().includes('/disable') && response.request().method() === 'POST'
  );
  await disableForm.locator('button[type="submit"]').click();
  await expect(page.locator('[data-admin-confirm]')).toBeVisible();
  await page.locator('[data-admin-confirm] [data-confirm-accept]').click();
  const disableResponse = await disableResponsePromise;
  expect(disableResponse.status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  const disabledRow = page.locator('table.admin-table tbody tr').filter({ hasText: 'Nexora E2E QA Module' });
  await expect(disabledRow).toContainText('disabled');
  await expectNoServerError(page);
});
