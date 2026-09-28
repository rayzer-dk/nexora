import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

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

test('admin settings persist and extension lifecycle is operational', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating admin audit runs once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');

  await loginAdmin(page);
  page.on('dialog', async (dialog) => dialog.accept());

  await page.goto('/admin/system/store', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const storeForm = page.locator('form.admin-editor-card[data-dirty-guard]').first();
  await expect(storeForm).toBeVisible();
  const storeName = storeForm.locator('input[name="name"]');
  const originalStoreName = await storeName.inputValue();
  const qaStoreName = `${originalStoreName} QA`;
  await storeName.fill(qaStoreName);
  await Promise.all([
    page.waitForURL(/\/admin\/system\/store/),
    storeForm.locator('button[type="submit"]').click(),
  ]);
  await expect(page.locator('input[name="name"]')).toHaveValue(qaStoreName);

  const restoredStoreForm = page.locator('form.admin-editor-card[data-dirty-guard]').first();
  await restoredStoreForm.locator('input[name="name"]').fill(originalStoreName);
  await Promise.all([
    page.waitForURL(/\/admin\/system\/store/),
    restoredStoreForm.locator('button[type="submit"]').click(),
  ]);
  await expect(page.locator('input[name="name"]')).toHaveValue(originalStoreName);

  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const appearanceForm = page.locator('form.admin-storefront-form');
  await expect(appearanceForm).toBeVisible();
  const subtitle = appearanceForm.locator('input[name="brand_subtitle"]');
  const originalSubtitle = await subtitle.inputValue();
  const originalRadius = await appearanceForm.locator('input[name="theme_radius"]').inputValue();
  const qaSubtitle = `Nexora E2E presentation ${Date.now()}`;
  const qaRadius = originalRadius === '19' ? '20' : '19';
  await subtitle.fill(qaSubtitle);
  await appearanceForm.locator('input[name="theme_radius"]').evaluate((element, value) => {
    const input = element as HTMLInputElement;
    input.value = String(value);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }, qaRadius);
  await Promise.all([
    page.waitForURL(/\/admin\/appearance\/storefront/),
    appearanceForm.locator('button[type="submit"]').last().click(),
  ]);
  await expect(page.locator('input[name="brand_subtitle"]')).toHaveValue(qaSubtitle);
  await expect(page.locator('input[name="theme_radius"]')).toHaveValue(qaRadius);

  const restoreAppearance = page.locator('form.admin-storefront-form');
  await restoreAppearance.locator('input[name="brand_subtitle"]').fill(originalSubtitle);
  await restoreAppearance.locator('input[name="theme_radius"]').evaluate((element, value) => {
    const input = element as HTMLInputElement;
    input.value = String(value);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }, originalRadius);
  await Promise.all([
    page.waitForURL(/\/admin\/appearance\/storefront/),
    restoreAppearance.locator('button[type="submit"]').last().click(),
  ]);
  await expect(page.locator('input[name="brand_subtitle"]')).toHaveValue(originalSubtitle);
  await expect(page.locator('input[name="theme_radius"]')).toHaveValue(originalRadius);

  await page.goto('/admin/system/extensions', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const packageInput = page.locator('input[name="extension_package"]');
  await packageInput.setInputFiles(path.resolve('var/e2e-extension.zip'));
  await Promise.all([
    page.waitForURL(/\/admin\/system\/extensions/),
    page.locator('form.admin-extension-upload button[type="submit"]').click(),
  ]);
  await expectNoServerError(page);

  const extensionRow = page.locator('table.admin-table tbody tr').filter({ hasText: 'Nexora E2E QA Module' });
  await expect(extensionRow).toBeVisible();
  await expect(extensionRow).toContainText('staged');

  const activateForm = extensionRow.locator('form[action$="/activate"]');
  await Promise.all([
    page.waitForURL(/\/admin\/system\/extensions/),
    activateForm.locator('button[type="submit"]').click(),
  ]);
  const activeRow = page.locator('table.admin-table tbody tr').filter({ hasText: 'Nexora E2E QA Module' });
  await expect(activeRow).toContainText('active');

  const disableForm = activeRow.locator('form[action$="/disable"]');
  await Promise.all([
    page.waitForURL(/\/admin\/system\/extensions/),
    disableForm.locator('button[type="submit"]').click(),
  ]);
  const disabledRow = page.locator('table.admin-table tbody tr').filter({ hasText: 'Nexora E2E QA Module' });
  await expect(disabledRow).toContainText('disabled');
  await expectNoServerError(page);
});
