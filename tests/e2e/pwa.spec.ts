import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError, loginAdmin } from './helpers';

test.describe.configure({ retries: 0 });

async function setPwa(page: Page, enabled: boolean, workbox: boolean): Promise<void> {
  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="brand_pwa"]').setChecked(enabled);
  await page.locator('input[name="brand_workbox"]').setChecked(workbox);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('form:has(input[name="brand_pwa"]) button[type="submit"]').first().click(),
  ]);
  await expectNoServerError(page);
}

test('Workbox is independent, opt-in and protects private commerce pages', async ({ page, request }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating appearance audit runs once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');

  const offline = await page.goto('/offline-pwa.html', { waitUntil: 'domcontentloaded' });
  expect(offline?.status()).toBe(200);
  expect((await offline?.body())?.toString()).toContain('noindex');

  await loginAdmin(page);
  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  const previousPwa = await page.locator('input[name="brand_pwa"]').isChecked();
  const previousWorkbox = await page.locator('input[name="brand_workbox"]').isChecked();
  try {
    await setPwa(page, true, false);
    const disabled = await (await request.get('/sw.js')).text();
    expect(disabled).toContain('unregister');
    expect(disabled).not.toContain('workbox.setConfig');

    await setPwa(page, true, true);
    const manifest = await request.get('/manifest.webmanifest');
    expect(manifest.status()).toBe(200);
    const json = await manifest.json();
    expect(json.name).toBeTruthy();
    expect(json.display).toBe('standalone');
    expect(json.icons.length).toBeGreaterThan(0);

    const sw = await request.get('/sw.js');
    expect(sw.status()).toBe(200);
    expect(sw.headers()['content-type']).toContain('javascript');
    expect(sw.headers()['service-worker-allowed']).toBe('/');
    const body = await sw.text();
    expect(body).not.toContain('__VERSION__');
    expect(body).toContain('workbox.setConfig');
    expect(body).toContain('workbox.precaching.precacheAndRoute');
    expect(body).toContain("importScripts('/nexora-push-sw.js')");
    expect(body).not.toContain('nx-static-${VERSION}');
    expect(body).toContain('/offline-pwa.html');

    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('link[rel="manifest"]')).toHaveCount(1);
    await expect(page.locator('html')).toHaveAttribute('data-workbox', '1');

    await setPwa(page, false, true);
    expect((await request.get('/manifest.webmanifest')).status()).toBe(404);
    expect(await (await request.get('/sw.js')).text()).toContain('unregister');
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('link[rel="manifest"]')).toHaveCount(0);
  } finally {
    await setPwa(page, previousPwa, previousWorkbox);
  }
});
