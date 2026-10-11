import { expect, test, type Page } from '@playwright/test';

// Release 3.97.24: a catalogue moves in from the product file of another platform.

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

test('a Shopify product file is imported: product page, old address redirect, brand, category and photo line', async ({ page, request }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared demo data once.');
  test.setTimeout(180_000);
  const stamp = Date.now();
  const handle = `e2e-moved-mug-${stamp}`;
  const photo = 'http://127.0.0.1:8099/old-shop/photo.png'; // the "old shop" is the small fixture server, because the shop itself answers one request at a time
  const csv = [
    'Handle,Title,Body (HTML),Vendor,Product Category,Option1 Name,Option1 Value,Variant SKU,Variant Inventory Qty,Variant Price,Variant Compare At Price,Image Src,Status',
    `${handle},Moved mug ${stamp},"<p>Moved from Shopify</p>",MoveBrand ${stamp},Home > Moved kitchen ${stamp},Size,S,MOVE-${stamp}-S,4,120.00,150.00,${photo},active`,
    `${handle},,,,,,L,MOVE-${stamp}-L,6,140.00,,,`,
  ].join('\n');
  await loginAdmin(page);
  await page.goto('/admin/system/migration', { waitUntil: 'domcontentloaded' });
  const panel = page.locator('#file-import');
  await panel.locator('input[type="file"]').setInputFiles({ name: 'products_export_1.csv', mimeType: 'text/csv', buffer: Buffer.from(csv, 'utf8') });

  // the check only reads the file
  await Promise.all([page.waitForURL(/migration\/file/), panel.locator('button[name="mode"][value="check"]').click()]);
  await expect(page.locator('#file-import')).toContainText('Shopify');
  await expect(page.locator('#file-import .admin-notice.is-success')).toBeAttached();
  expect((await request.get(`/${handle}`, { maxRedirects: 0 })).status()).toBe(404);

  // the import writes it
  const again = page.locator('#file-import');
  await again.locator('input[type="file"]').setInputFiles({ name: 'products_export_1.csv', mimeType: 'text/csv', buffer: Buffer.from(csv, 'utf8') });
  await again.locator('button[name="mode"][value="apply"]').click();
  await page.locator('[data-confirm-accept]').click();
  await page.waitForURL(/migration\/file/);
  await expect(page.locator('#file-import .admin-notice.is-success')).toContainText(/(створено|created) 4/);

  // the product is in the shop under its old handle, the old Shopify address redirects to it
  const product = await request.get(`/${handle}`);
  expect(product.status()).toBe(200);
  expect(await product.text()).toContain(`Moved mug ${stamp}`);
  const old = await request.get(`/products/${handle}`, { maxRedirects: 0 });
  expect(old.status()).toBe(301);
  expect(old.headers()['location']).toContain(handle);

  // the photo line downloads the picture from the "old shop" and attaches it
  await expect(page.locator('[data-image-queue]')).toBeVisible();
  await page.locator('[data-image-queue-run]').first().click();
  await expect(page.locator('[data-image-queue-text]')).toContainText(/(Завантажено|Downloaded) [1-9]/, { timeout: 90_000 });
  await page.reload({ waitUntil: 'domcontentloaded' });
  const withPhoto = await (await request.get(`/${handle}`)).text();
  expect(withPhoto).toMatch(/\/media\/[^"']+\.(webp|jpg|png|avif)/);

  // the same file again creates nothing new
  await page.goto('/admin/system/migration', { waitUntil: 'domcontentloaded' });
  await page.locator('#file-import input[type="file"]').setInputFiles({ name: 'products_export_1.csv', mimeType: 'text/csv', buffer: Buffer.from(csv, 'utf8') });
  await page.locator('#file-import button[name="mode"][value="apply"]').click();
  await page.locator('[data-confirm-accept]').click();
  await page.waitForURL(/migration\/file/);
  await expect(page.locator('#file-import .admin-notice.is-success')).toContainText(/(створено|created) 0/);
});
