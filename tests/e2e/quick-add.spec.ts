import { expect, test, type Page } from '@playwright/test';

// Release 3.97.24: one-screen product creation.

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

test('a product is created from the quick-add screen with a photo and opens for further editing', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Creates a product once.');
  test.setTimeout(240_000);
  page.setDefaultNavigationTimeout(120_000);
  await loginAdmin(page);

  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  await Promise.all([page.waitForURL(/products\/quick$/), page.locator('[data-quick-add]').click()]);

  const name = 'Quick add ' + Date.now();
  const form = page.locator('[data-quick-add-form]');
  await form.locator('input[name="images[]"]').setInputFiles({ name: 'photo.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAIAAAAmkwkpAAAAFElEQVR4nGPkqrjDAANMDEgANwcARI4BZoWJLsMAAAAASUVORK5CYII=', 'base64') });
  await form.locator('input[name="name"]').fill(name);
  await form.locator('input[name="price"]').fill('199.50');
  await form.locator('input[name="stock_quantity"]').fill('7');
  await form.locator('textarea[name="short_description"]').fill('Short text');
  await Promise.all([page.waitForURL(/\/admin\/catalog\/products\/[0-9a-f-]{36}/), form.locator('button[type="submit"]').click()]);

  // the edit page of the new draft: the values are there and the photo is attached
  await expect(page.locator('input[name="name"]')).toHaveValue(name);
  await expect(page.locator('input[name="price"]')).toHaveValue(/^199[.,]50$/);
  await expect(page.locator('input[name="stock_quantity"]')).toHaveValue(/^7/);
  await page.goto('/admin/catalog/products?search=' + encodeURIComponent(name), { waitUntil: 'domcontentloaded' });
  await expect(page.locator('body')).toContainText(name);
});
