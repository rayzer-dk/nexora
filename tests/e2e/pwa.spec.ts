import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0 });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/|$)/), page.locator('button[type="submit"]').click()]);
}

async function setPwa(page: Page, enabled: boolean): Promise<void> {
  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="brand_pwa"]').setChecked(enabled);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('form:has(input[name="brand_pwa"]) button[type="submit"]').first().click(),
  ]);
  await expectNoServerError(page);
}

test('PWA: manifest, service worker and offline page are served and can be switched off', async ({ page, request }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating appearance audit runs once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');

  const manifest = await request.get('/manifest.webmanifest');
  expect(manifest.status()).toBe(200);
  const json = await manifest.json();
  expect(json.name).toBeTruthy();
  expect(json.display).toBe('standalone');
  expect(json.start_url).toBeTruthy();
  expect(json.icons.length).toBeGreaterThan(0);

  const sw = await request.get('/sw.js');
  expect(sw.status()).toBe(200);
  expect(sw.headers()['content-type']).toContain('javascript');
  expect(sw.headers()['service-worker-allowed']).toBe('/');
  const swBody = await sw.text();
  expect(swBody).not.toContain('__VERSION__');
  expect(swBody).toContain('/offline');

  const offline = await page.goto('/offline', { waitUntil: 'domcontentloaded' });
  expect(offline?.status()).toBe(200);
  expect(offline?.headers()['x-robots-tag'] ?? '').toContain('noindex');

  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('link[rel="manifest"]')).toHaveCount(1);

  await loginAdmin(page);
  try {
    await setPwa(page, false);
    expect((await request.get('/manifest.webmanifest')).status()).toBe(404);
    const off = await request.get('/sw.js');
    expect(off.status()).toBe(200);
    expect(await off.text()).toContain('unregister');
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('link[rel="manifest"]')).toHaveCount(0);
  } finally {
    await setPwa(page, true);
  }
  expect((await request.get('/manifest.webmanifest')).status()).toBe(200);
});
