import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL(/\/admin(?:\/|$)/),
    page.locator('button[type="submit"]').click(),
  ]);
}

const KEYS = ['background', 'text', 'heading', 'header_bg', 'footer_bg', 'primary_hover', 'primary_active', 'buy_button', 'buy_hover', 'buy_active'] as const;

async function saveColors(page: Page, values: Partial<Record<(typeof KEYS)[number], string>>) {
  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const panel = page.locator('[data-color-settings]');
  for (const key of KEYS) {
    const on = panel.locator(`input[name="colors_${key}_on"]`);
    const value = values[key];
    if (value) {
      await panel.locator(`input[name="colors_${key}"]`).fill(value);
      await on.check();
    } else {
      await on.uncheck();
    }
  }
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/admin/appearance/storefront')),
    page.locator('form[data-dirty-guard] button[type="submit"]').last().click(),
  ]);
}

const rgb = (hex: string) => {
  const n = parseInt(hex.slice(1), 16);
  return `rgb(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255})`;
};

test('extra colours set in Appearance reach the storefront and can be reset to the style', async ({ page, browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings run once.');
  await loginAdmin(page);
  try {
    await saveColors(page, { background: '#FFF7ED', heading: '#9A3412', header_bg: '#7C2D12', footer_bg: '#431407', buy_button: '#16A34A', buy_hover: '#15803D' });
    const ctx = await browser.newContext({ baseURL: testInfo.project.use.baseURL, locale: 'uk-UA' });
    const shop = await ctx.newPage();
    await shop.goto('/catalog', { waitUntil: 'domcontentloaded' });
    // colours may still be transitioning right after load, so the assertions retry
    await expect(shop.locator('body')).toHaveCSS('background-color', rgb('#FFF7ED'));
    await expect(shop.locator('.reference-header')).toHaveCSS('background-color', rgb('#7C2D12'));
    await expect(shop.locator('.site-footer')).toHaveCSS('background-color', rgb('#431407'));
    await expect(shop.locator('main h1').first()).toHaveCSS('color', rgb('#9A3412'));
    await expect(shop.locator('.catalog-card__buy').first()).toHaveCSS('background-color', rgb('#16A34A'));
    await shop.locator('.catalog-card__buy').first().hover();
    await expect(shop.locator('.catalog-card__buy').first()).toHaveCSS('background-color', rgb('#15803D'));
    await ctx.close();
  } finally {
    await saveColors(page, {});
  }
  const ctx = await browser.newContext({ baseURL: testInfo.project.use.baseURL, locale: 'uk-UA' });
  const shop = await ctx.newPage();
  await shop.goto('/catalog', { waitUntil: 'domcontentloaded' });
  await expect(shop.locator('.catalog-card__buy').first()).not.toHaveCSS('background-color', rgb('#16A34A'));
  await ctx.close();
});
