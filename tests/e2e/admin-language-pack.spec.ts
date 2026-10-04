import { expect, test, type Page } from '@playwright/test';

test.describe.configure({ retries: 0, mode: 'serial' });

async function signIn(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

test('language packs: texts download as JSON and a broken file is refused with a message', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Shared admin session runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await signIn(page);
  await page.goto('/admin/system/localization', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#language-packs')).toBeVisible();

  const download = await page.request.get('/admin/system/localization/pack/en-US');
  expect(download.status()).toBe(200);
  expect(download.headers()['content-disposition']).toContain('storefront-en-US.json');
  const texts = (await download.json()) as Record<string, string>;
  expect(Object.keys(texts).length).toBeGreaterThan(500);
  expect(Object.values(texts).every((value) => typeof value === 'string')).toBe(true);

  const adminTexts = (await (await page.request.get('/admin/system/localization/pack/en-US?scope=admin')).json()) as Record<string, string>;
  expect(Object.keys(adminTexts).length).toBeGreaterThan(3000);
  expect((await page.request.get('/admin/system/localization/pack/en-US?scope=nope')).status()).toBe(200); // an unknown scope falls back to the storefront texts

  const upload = async (content: string): Promise<void> => {
    const form = page.locator('#language-packs form');
    await form.locator('select[name="pack_locale"]').selectOption('da-DK');
    await form.locator('input[name="pack_file"]').setInputFiles({ name: 'pack.json', mimeType: 'application/json', buffer: Buffer.from(content) });
    await Promise.all([page.waitForURL(/\/admin\/system\/localization/), form.locator('button[type="submit"]').click()]);
  };
  await upload('{"cart": "Kurv",');
  await expect(page.locator('.admin-notice.is-error').first()).toBeAttached();
  await page.goto('/admin/system/localization', { waitUntil: 'domcontentloaded' });
  await upload(JSON.stringify({ 'no.such.key': 'x' }));
  await expect(page.locator('.admin-notice.is-error').first()).toBeAttached();
  expect((await page.request.get('/admin/system/localization/pack/not_a_locale')).status()).toBe(404);
});
