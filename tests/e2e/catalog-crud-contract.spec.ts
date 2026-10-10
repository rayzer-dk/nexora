import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError, openMoreFields, openProductTab } from './helpers';

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL(/\/admin(?:\/(?!login)|$)/),
    page.locator('button[type="submit"]').click(),
  ]);
}

test('admin catalog create, publish, stock-price update and delete are reflected by storefront', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating catalog CRUD runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  const suffix = Date.now();
  const categoryName = `E2E Category ${suffix}`;
  const categorySlug = `e2e-category-${suffix}`;
  const productName = `E2E Product ${suffix}`;
  const productSlug = `e2e-product-${suffix}`;
  const sku = `E2E-${suffix}`;

  await loginAdmin(page);
  await page.goto('/admin/catalog/categories/new', { waitUntil: 'domcontentloaded' });
  const categoryForm = page.locator('form.admin-form');
  await categoryForm.locator('input[name="name"]').fill(categoryName);
  await categoryForm.locator('input[name="slug"]').fill(categorySlug);
  const categoryCreate = page.waitForResponse((response) =>
    response.url().endsWith('/admin/catalog/categories/new') && response.request().method() === 'POST'
  );
  await categoryForm.locator('button[type="submit"]').click();
  expect((await categoryCreate).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('table tbody tr').filter({ hasText: categoryName })).toBeVisible();

  await page.goto('/admin/catalog/products/new', { waitUntil: 'domcontentloaded' });
  const productForm = page.locator('form.admin-form');
  await productForm.locator('input[name="name"]').fill(productName);
  await productForm.locator('input[name="sku"]').fill(sku);
  await openMoreFields(page);
  await productForm.locator('input[name="slug"]').fill(productSlug);
  await productForm.locator('textarea[name="short_description"]').fill('E2E storefront catalog contract.');
  const descriptionEditor = productForm.locator('textarea[name="description"] + .rich-editor-mount .ProseMirror');
  await expect(descriptionEditor).toBeVisible();
  await descriptionEditor.click();
  await page.keyboard.type('E2E product body.');
  await openProductTab(page, 'sales');
  await productForm.locator('input[name="price"]').fill('123.45');
  await productForm.locator('input[name="stock_quantity"]').fill('3');
  await openProductTab(page, 'general');
  await productForm.locator('.admin-multiselect summary').first().click();
  const categoryCheckbox = productForm.locator('label').filter({ hasText: categoryName }).locator('input[name="category_ids[]"]');
  await expect(categoryCheckbox).toBeVisible();
  await categoryCheckbox.check();
  await page.keyboard.press('Escape');

  const productCreate = page.waitForResponse((response) =>
    response.url().endsWith('/admin/catalog/products/new') && response.request().method() === 'POST'
  );
  await productForm.locator('.admin-form-actions button[type="submit"]').click();
  expect((await productCreate).status()).toBeLessThan(400);
  await page.waitForURL(/\/admin\/catalog\/products\/[0-9a-f-]{36}\/edit/);
  const productPublicId = page.url().match(/products\/([0-9a-f-]{36})\/edit/)?.[1] || '';
  expect(productPublicId).not.toBe('');

  const editForm = page.locator('form.admin-form');
  await editForm.locator('select[name="status"]').selectOption('published');
  const publishResponse = page.waitForResponse((response) =>
    response.url().includes(`/admin/catalog/products/${productPublicId}/edit`) && response.request().method() === 'POST'
  );
  await editForm.locator('.admin-form-actions button[type="submit"]').click();
  expect((await publishResponse).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');

  const storefront = await page.goto('/' + productSlug, { waitUntil: 'domcontentloaded' });
  expect(storefront?.status()).toBe(200);
  await expectNoServerError(page);
  await expect(page.locator('h1')).toContainText(productName);
  await expect(page.locator('form[data-buy-actions]')).toBeVisible();
  await expect(page.locator('body')).toContainText('123');

  await page.goto(`/admin/catalog/products/${productPublicId}/edit`, { waitUntil: 'domcontentloaded' });
  const updateForm = page.locator('form.admin-form');
  await openProductTab(page, 'sales');
  await updateForm.locator('input[name="price"]').fill('321.45');
  await updateForm.locator('input[name="stock_quantity"]').fill('0');
  const updateResponse = page.waitForResponse((response) =>
    response.url().includes(`/admin/catalog/products/${productPublicId}/edit`) && response.request().method() === 'POST'
  );
  await updateForm.locator('.admin-form-actions button[type="submit"]').click();
  expect((await updateResponse).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');

  // Storefront catalog queries are cached for a few seconds by design; poll until the admin change is visible.
  await expect(async () => {
    await page.goto('/' + productSlug, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('body')).toContainText('321', { timeout: 1000 });
    await expect(page.locator('form[data-buy-actions]')).toHaveCount(0, { timeout: 1000 });
  }).toPass({ timeout: 20_000 });

  await page.goto('/admin/catalog/products?search=' + encodeURIComponent(sku), { waitUntil: 'domcontentloaded' });
  const productRow = page.locator('table tbody tr').filter({ hasText: sku }).first();
  await expect(productRow).toBeVisible();
  const deleteProduct = page.locator(`form[action="/admin/catalog/products/${productPublicId}/delete"]`);
  const productDeleteResponse = page.waitForResponse((response) =>
    response.url().endsWith(`/admin/catalog/products/${productPublicId}/delete`) && response.request().method() === 'POST'
  );
  await deleteProduct.evaluate((form: HTMLFormElement) => form.requestSubmit());
  expect((await productDeleteResponse).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');

  const deletedStorefront = await page.goto('/' + productSlug, { waitUntil: 'domcontentloaded' });
  expect(deletedStorefront?.status()).toBe(404);

  await page.goto('/admin/catalog/categories', { waitUntil: 'domcontentloaded' });
  const categoryRow = page.locator('table tbody tr').filter({ hasText: categoryName }).first();
  await expect(categoryRow).toBeVisible();
  const categoryDelete = categoryRow.locator('form[action$="/delete"]');
  const categoryDeleteResponse = page.waitForResponse((response) =>
    response.url().includes('/admin/catalog/categories/') && response.url().endsWith('/delete') && response.request().method() === 'POST'
  );
  await categoryDelete.evaluate((form: HTMLFormElement) => form.requestSubmit());
  expect((await categoryDeleteResponse).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('table tbody tr').filter({ hasText: categoryName })).toHaveCount(0);
});
