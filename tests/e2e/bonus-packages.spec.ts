import AxeBuilder from '@axe-core/playwright';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0 });
test.setTimeout(300_000);

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')), page.locator('button[type="submit"]').click()]);
}

async function upload(page: Page, file: string): Promise<void> {
  await page.goto('/admin/system/extensions', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="extension_package"]').setInputFiles(path.resolve(file));
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/admin/system/extensions')),
    page.locator('form.admin-extension-upload button[type="submit"]').click(),
  ]);
  await page.goto('/admin/system/extensions', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
}

const rowOf = (page: Page, name: string) => page.locator('table.admin-table').first().locator('tbody tr').filter({ hasText: name });

async function act(page: Page, name: string, action: 'activate' | 'disable' | 'uninstall'): Promise<void> {
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes(`/${action}`)),
    (async () => {
      await rowOf(page, name).locator(`form[action$="/${action}"] button`).click();
      await expect(page.locator('[data-admin-confirm]')).toBeVisible();
      await page.locator('[data-admin-confirm] [data-confirm-accept]').click();
    })(),
  ]);
  await page.goto('/admin/system/extensions', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
}

async function setScheme(page: Page, scheme: 'light' | 'dark'): Promise<void> {
  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  await page.locator('select[name="theme_color_scheme"]').selectOption(scheme);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('form:has(select[name="theme_color_scheme"]) button[type="submit"]').first().click(),
  ]);
}

const themes = [
  { name: 'Nexora Market', file: 'bonus/themes/packages/Nexora_Market_Theme_v1.0.0.zip', code: 'nexora.theme_market' },
  { name: 'Nexora Atelier', file: 'bonus/themes/packages/Nexora_Atelier_Theme_v1.0.0.zip', code: 'nexora.theme_atelier' },
  { name: 'Nexora Soft', file: 'bonus/themes/packages/Nexora_Soft_Theme_v1.0.0.zip', code: 'nexora.theme_soft' },
];

test('bonus themes install, restyle the storefront, stay readable in light and dark, and uninstall cleanly', async ({ page, browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating extension audit runs once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');
  await loginAdmin(page);
  const shopContext = await browser.newContext({ baseURL: testInfo.project.use.baseURL });
  const shop = await shopContext.newPage();
  const problems: string[] = [];
  try {
    await shop.goto('/', { waitUntil: 'domcontentloaded' });
    const productHref = await shop.locator('[data-product-card] a.catalog-card__media').first().getAttribute('href');
    const baseline = await shop.locator('body').evaluate((el) => getComputedStyle(el).getPropertyValue('--theme-primary'));

    for (const theme of themes) {
      await upload(page, theme.file);
      await expect(rowOf(page, theme.name)).toContainText('staged');
      await act(page, theme.name, 'activate');
      await expect(rowOf(page, theme.name)).toContainText('active');

      for (const scheme of ['light', 'dark'] as const) {
        await setScheme(page, scheme);
        for (const route of ['/', '/catalog', productHref!]) {
          const response = await shop.goto(route, { waitUntil: 'domcontentloaded' });
          expect(response?.status(), `${theme.code} ${scheme} ${route}`).toBe(200);
          await shop.waitForLoadState('networkidle').catch(() => undefined);
          const stylesheet = await shop.locator(`link[rel="stylesheet"][href*="/media/extensions/${theme.code}/"]`).first().getAttribute('href');
          expect(stylesheet, `${theme.code} stylesheet linked`).toBeTruthy();
          if (route === '/' && scheme === 'light') {
            const css = await shop.request.get(stylesheet!);
            expect(css.status()).toBe(200);
            expect(await shop.locator('body').evaluate((el) => getComputedStyle(el).getPropertyValue('--theme-primary'))).not.toBe(baseline);
          }
          const axe = await new AxeBuilder({ page: shop }).withRules(['color-contrast']).analyze();
          for (const v of axe.violations) for (const n of v.nodes) problems.push(`${theme.code} ${scheme} ${route} ${n.target.join(' ')} ${JSON.stringify(n.any[0]?.data ?? {})}`);
        }
      }
      await setScheme(page, 'light');
      await page.goto('/admin/system/extensions', { waitUntil: 'domcontentloaded' });
      await act(page, theme.name, 'disable');
      await act(page, theme.name, 'uninstall');
      await expect(rowOf(page, theme.name)).toHaveCount(0);
    }
    expect(problems, problems.slice(0, 30).join('\n')).toEqual([]);
    const after = await shop.goto('/', { waitUntil: 'domcontentloaded' });
    expect(after?.status()).toBe(200);
    await expect(shop.locator('link[rel="stylesheet"][href*="/media/extensions/nexora.theme_"]')).toHaveCount(0);
  } finally {
    await shopContext.close();
    await setScheme(page, 'light');
  }
});

test('bonus extensions install, run on the storefront and uninstall cleanly', async ({ page, request }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating extension audit runs once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');
  await loginAdmin(page);

  // Product Trust Badge: declarative slot on the product page.
  await upload(page, 'bonus/extensions/packages/Product_Trust_Badge_v1.0.1.zip');
  await act(page, 'Trust', 'activate');
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  const href = await page.locator('[data-product-card] a.catalog-card__media').first().getAttribute('href');
  await page.goto(href!, { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('[data-extension-slot="product.after_price"][data-extension-code="nexora.product_trust"]').first()).toBeVisible();
  await page.goto('/admin/system/extensions', { waitUntil: 'domcontentloaded' });
  await act(page, 'Trust', 'disable');
  await act(page, 'Trust', 'uninstall');

  // Store Health: signed trusted PHP route.
  await upload(page, 'bonus/extensions/packages/Store_Health_Endpoint_v1.0.0.zip');
  await expect(rowOf(page, 'Health')).toContainText('staged');
  expect((await request.get('/extensions/nexora-store-health/status')).status()).toBe(404);
  await act(page, 'Health', 'activate');
  const health = await request.get('/extensions/nexora-store-health/status');
  expect(health.status()).toBe(200);
  expect(health.headers()['content-type']).toContain('json');
  await page.goto('/admin/system/extensions', { waitUntil: 'domcontentloaded' });
  await act(page, 'Health', 'disable');
  expect((await request.get('/extensions/nexora-store-health/status')).status()).toBe(404);
  await act(page, 'Health', 'uninstall');

  // Remote CRM starter: remote_app with a settings page.
  await upload(page, 'bonus/extensions/packages/Remote_CRM_Connector_Starter_v1.0.0.zip');
  await act(page, 'CRM', 'activate');
  await rowOf(page, 'CRM').locator('a[href$="/settings"]').click();
  await expectNoServerError(page);
  await expect(page.locator('form[data-dirty-guard]')).toBeVisible();
  await page.goto('/admin/system/extensions', { waitUntil: 'domcontentloaded' });
  await act(page, 'CRM', 'disable');
  await act(page, 'CRM', 'uninstall');
});
