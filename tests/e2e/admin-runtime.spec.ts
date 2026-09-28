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

test('admin settings persist and extension lifecycle is operational', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating admin audit runs once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');

  await loginAdmin(page);
  await page.goto('/admin/system/store', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const storeForm = page.locator('form.admin-editor-card[data-dirty-guard]').first();
  await expect(storeForm).toBeVisible();
  const storeName = storeForm.locator('input[name="name"]');
  const originalStoreName = await storeName.inputValue();
  const qaStoreName = `${originalStoreName} QA`;
  await storeName.fill(qaStoreName);
  const saveStoreResponsePromise = page.waitForResponse((response) =>
    response.url().includes('/admin/system/store') && response.request().method() === 'POST'
  );
  await storeForm.locator('button[type="submit"]').click();
  const saveStoreResponse = await saveStoreResponsePromise;
  expect(saveStoreResponse.status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('.store-notice.is-error')).toHaveCount(0);
  await expect(page.locator('input[name="name"]')).toHaveValue(qaStoreName);

  const restoredStoreForm = page.locator('form.admin-editor-card[data-dirty-guard]').first();
  await restoredStoreForm.locator('input[name="name"]').fill(originalStoreName);
  const restoreStoreResponsePromise = page.waitForResponse((response) =>
    response.url().includes('/admin/system/store') && response.request().method() === 'POST'
  );
  await restoredStoreForm.locator('button[type="submit"]').click();
  const restoreStoreResponse = await restoreStoreResponsePromise;
  expect(restoreStoreResponse.status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('.store-notice.is-error')).toHaveCount(0);
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
  const saveAppearance = page.waitForResponse((response) =>
    response.url().includes('/admin/appearance/storefront') &&
    response.request().method() === 'POST'
  );
  await appearanceForm.locator('button[type="submit"]').last().click();
  const saveAppearanceResponse = await saveAppearance;
  expect(saveAppearanceResponse.status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
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
  const restoreAppearanceResponsePromise = page.waitForResponse((response) =>
    response.url().includes('/admin/appearance/storefront') &&
    response.request().method() === 'POST'
  );
  await restoreAppearance.locator('button[type="submit"]').last().click();
  const restoreAppearanceResponse = await restoreAppearanceResponsePromise;
  expect(restoreAppearanceResponse.status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('input[name="brand_subtitle"]')).toHaveValue(originalSubtitle);
  await expect(page.locator('input[name="theme_radius"]')).toHaveValue(originalRadius);

  await expectNoServerError(page);
});
