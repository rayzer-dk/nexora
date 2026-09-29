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
}

test('Media Library upload, metadata, search and store removal form one real lifecycle', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating media lifecycle runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  await page.goto('/admin/media', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);

  const marker = `E2E media ${Date.now()}`;
  const upload = page.locator('form[action="/admin/media/upload"]');
  await upload.locator('input[type="file"]').setInputFiles({
    name: 'e2e-media.png',
    mimeType: 'image/png',
    buffer: Buffer.from(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6dQAAAABJRU5ErkJggg==',
      'base64',
    ),
  });

  const uploadResponse = page.waitForResponse((response) =>
    response.url().endsWith('/admin/media/upload') && response.request().method() === 'POST'
  );
  await upload.locator('button[type="submit"]').click();
  expect((await uploadResponse).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('.admin-flash--error')).toHaveCount(0);

  const card = page.locator('.media-card').first();
  await expect(card).toBeVisible();
  const editButton = card.locator('[data-media-edit]').first();
  const assetId = await editButton.getAttribute('data-media-edit');
  expect(assetId).toMatch(/^\d+$/);
  await editButton.click();

  const metadataForm = card.locator(`form[action="/admin/media/${assetId}/metadata"]`);
  await metadataForm.locator('input[name="title"]').fill(marker);
  await metadataForm.locator('input[name="alt_text"]').fill(`${marker} alt`);
  await metadataForm.locator('input[name="tags"]').fill('e2e, media-contract');
  const metadataResponse = page.waitForResponse((response) =>
    response.url().endsWith(`/admin/media/${assetId}/metadata`) && response.request().method() === 'POST'
  );
  await metadataForm.locator('button[type="submit"]').click();
  expect((await metadataResponse).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');

  const searchResponse = await page.request.get('/admin/media.json?q=' + encodeURIComponent(marker));
  expect(searchResponse.status()).toBe(200);
  const found = await searchResponse.json();
  expect(found.total).toBe(1);
  expect(found.items[0].title).toBe(marker);
  expect(found.items[0].alt_text).toBe(`${marker} alt`);
  expect(found.items[0].tags).toContain('media-contract');

  await page.goto('/admin/media?q=' + encodeURIComponent(marker), { waitUntil: 'domcontentloaded' });
  const scopedCard = page.locator('.media-card').filter({ hasText: marker });
  await expect(scopedCard).toBeVisible();
  await scopedCard.locator('[data-media-edit]').first().click();

  const deleteForm = scopedCard.locator(`form[action="/admin/media/${assetId}/delete"]`);
  const deleteResponse = page.waitForResponse((response) =>
    response.url().endsWith(`/admin/media/${assetId}/delete`) && response.request().method() === 'POST'
  );
  await deleteForm.evaluate((form: HTMLFormElement) => form.requestSubmit());
  expect((await deleteResponse).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');

  const after = await page.request.get('/admin/media.json?q=' + encodeURIComponent(marker));
  const afterPayload = await after.json();
  expect(afterPayload.total).toBe(0);
});
