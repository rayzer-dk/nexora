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
  const storeForm = page.locator('form.admin-panel[data-dirty-guard]').first();
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
  await expect(page.locator('.admin-notice.is-error')).toHaveCount(0);
  await expect(page.locator('input[name="name"]')).toHaveValue(qaStoreName);

  await page.goto('/admin/system/store', { waitUntil: 'domcontentloaded' });
  const restoredStoreForm = page.locator('form.admin-panel[data-dirty-guard]').first();
  await restoredStoreForm.locator('input[name="name"]').fill(originalStoreName);
  const restoreStoreResponsePromise = page.waitForResponse((response) =>
    response.url().includes('/admin/system/store') && response.request().method() === 'POST'
  );
  await restoredStoreForm.locator('button[type="submit"]').click();
  const restoreStoreResponse = await restoreStoreResponsePromise;
  expect(restoreStoreResponse.status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('.admin-notice.is-error')).toHaveCount(0);
  await expect(page.locator('input[name="name"]')).toHaveValue(originalStoreName);

  await page.goto('/admin/system/site', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const siteForm = page.locator('form.admin-form[data-dirty-guard]');
  await expect(siteForm).toBeVisible();
  const originalMode = await siteForm.locator('input[name="mode"]:checked').inputValue();
  const originalFeatures = await siteForm.locator('input[name^="feature_"]').evaluateAll((inputs) =>
    Object.fromEntries(inputs.map((node) => {
      const input = node as HTMLInputElement;
      return [input.name, input.checked];
    }))
  ) as Record<string, boolean>;

  await siteForm.locator('input[name="mode"][value="content"]').check();
  await expect(siteForm.locator('input[name="feature_catalog"]')).not.toBeChecked();
  await expect(siteForm.locator('input[name="feature_cart"]')).not.toBeChecked();
  await expect(siteForm.locator('input[name="feature_checkout"]')).not.toBeChecked();
  await expect(siteForm.locator('input[name="feature_customer_accounts"]')).not.toBeChecked();

  const saveSiteResponsePromise = page.waitForResponse((response) =>
    response.url().includes('/admin/system/site') && response.request().method() === 'POST'
  );
  await siteForm.locator('button[type="submit"]').click();
  const saveSiteResponse = await saveSiteResponsePromise;
  expect(saveSiteResponse.status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('.admin-notice.is-error')).toHaveCount(0);
  await expect(page.locator('input[name="mode"][value="content"]')).toBeChecked();

  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.reference-search')).toHaveCount(0);
  await expect(page.locator('.reference-cart-link')).toHaveCount(0);
  await expect(page.locator('.reference-catalog-toggle')).toHaveCount(0);
  await expect(page.locator('.reference-account-link')).toHaveCount(0);

  for (const disabledPath of ['/catalog', '/cart', '/checkout', '/forum', '/account']) {
    const response = await page.goto(disabledPath, { waitUntil: 'domcontentloaded' });
    expect(response?.status(), disabledPath).toBe(404);
  }

  await page.goto('/admin/system/site', { waitUntil: 'domcontentloaded' });
  const restoreSiteForm = page.locator('form.admin-form[data-dirty-guard]');
  await restoreSiteForm.locator(`input[name="mode"][value="${originalMode}"]`).check();
  for (const [name, checked] of Object.entries(originalFeatures)) {
    await restoreSiteForm.locator(`input[name="${name}"]`).setChecked(checked);
  }
  const restoreSiteResponsePromise = page.waitForResponse((response) =>
    response.url().includes('/admin/system/site') && response.request().method() === 'POST'
  );
  await restoreSiteForm.locator('button[type="submit"]').click();
  const restoreSiteResponse = await restoreSiteResponsePromise;
  expect(restoreSiteResponse.status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('.admin-notice.is-error')).toHaveCount(0);
  await expect(page.locator(`input[name="mode"][value="${originalMode}"]`)).toBeChecked();

  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const appearanceForm = page.locator('form[data-appearance-media]');
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

  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('.reference-brand small')).toHaveText(qaSubtitle);

  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  const restoreAppearance = page.locator('form[data-appearance-media]');
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


test('progressive admin features initialize on their real pages', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Progressive feature audit runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');

  const pageErrors: string[] = [];
  page.on('pageerror', (error) => pageErrors.push(error.message));

  await loginAdmin(page);

  await page.goto('/admin/appearance/builder/product', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('[data-builder-list] .mc-builder-block').first()).toBeVisible();
  await page.locator('[data-builder-list] .mc-builder-select').first().click();
  await expect(page.locator('[data-inspector] h3')).toBeVisible();

  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const choose = page.locator('[data-media-choose]').first();
  await expect(choose).toBeVisible();
  await choose.click();
  await expect(page.locator('dialog.mc-picker')).toBeVisible();

  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  const edit = page.locator('a[href*="/admin/catalog/products/"][href$="/edit"]').first();
  await expect(edit).toBeVisible();
  await page.goto((await edit.getAttribute('href'))!, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.rich-editor')).toBeVisible();
  await expect(page.locator('textarea[data-rich-editor]')).toBeHidden();

  expect(pageErrors, pageErrors.join('\n')).toEqual([]);
});


test('navigation and content edits are reflected by the storefront', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating wiring audit runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');

  await loginAdmin(page);

  const menuLabel = `E2E navigation ${Date.now()}`;
  await page.goto('/admin/appearance/navigation?menu=header', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const navForm = page.locator('form[action="/admin/appearance/navigation/save"]');
  await expect(navForm).toBeVisible();
  await navForm.locator('select[name="item_type"]').selectOption('custom');
  await navForm.locator('input[name="url"]').fill('/contact');
  const labels = navForm.locator('input[name^="label["]');
  const labelCount = await labels.count();
  for (let i = 0; i < labelCount; i += 1) await labels.nth(i).fill(menuLabel);
  const navSave = page.waitForResponse((response) =>
    response.url().includes('/admin/appearance/navigation/save') && response.request().method() === 'POST'
  );
  await navForm.locator('button[type="submit"]').click();
  expect((await navSave).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');

  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('.reference-category-nav a').filter({ hasText: menuLabel })).toBeVisible();

  await page.goto('/admin/appearance/navigation?menu=header', { waitUntil: 'domcontentloaded' });
  const navRow = page.locator('table.admin-table tbody tr').filter({ hasText: menuLabel }).first();
  await expect(navRow).toBeVisible();
  const deleteForm = navRow.locator('form[action$="/delete"]');
  const deleteResponse = page.waitForResponse((response) =>
    response.url().includes('/admin/appearance/navigation/') &&
    response.url().endsWith('/delete') &&
    response.request().method() === 'POST'
  );
  await deleteForm.evaluate((form: HTMLFormElement) => form.requestSubmit());
  expect((await deleteResponse).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');

  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.reference-category-nav a').filter({ hasText: menuLabel })).toHaveCount(0);

  await page.goto('/admin/content/pages/about', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const contentForm = page.locator('form.admin-panel.admin-form');
  await expect(contentForm).toBeVisible();
  const title = contentForm.locator('input[name="title"]');
  const originalTitle = await title.inputValue();
  const qaTitle = `${originalTitle} E2E`;
  await title.fill(qaTitle);
  const contentSave = page.waitForResponse((response) =>
    response.url().includes('/admin/content/pages/about') && response.request().method() === 'POST'
  );
  await contentForm.locator('button[type="submit"]').click();
  expect((await contentSave).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');

  const publicPageResponse = await page.goto('/about-us', { waitUntil: 'domcontentloaded' });
  expect(publicPageResponse?.status() ?? 0).toBeLessThan(600);
  await expect(page.locator('h1')).toContainText(qaTitle);

  await page.goto('/admin/content/pages/about', { waitUntil: 'domcontentloaded' });
  const restoreContentForm = page.locator('form.admin-panel.admin-form');
  await restoreContentForm.locator('input[name="title"]').fill(originalTitle);
  const restoreContent = page.waitForResponse((response) =>
    response.url().includes('/admin/content/pages/about') && response.request().method() === 'POST'
  );
  await restoreContentForm.locator('button[type="submit"]').click();
  expect((await restoreContent).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expectNoServerError(page);
});
