import { expect, test } from '@playwright/test';
import { loginAdmin } from './helpers';

test('Dynamic preset selects the locally hosted Inter font without changing other presets', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Check the admin preset once.');
  await loginAdmin(page);
  await page.goto('/admin/appearance/storefront');
  const dynamic = page.locator('input[name="theme_preset"][value="dynamic"]');
  await expect(dynamic).toHaveCount(1);
  await dynamic.check();
  await expect(page.locator('[name="theme_font"]')).toHaveValue('inter');
  await expect(page.locator('[name="theme_radius"]')).toHaveValue('8');
  await expect(page.locator('[name="theme_shadow"]')).toHaveValue('none');
  await page.locator('input[name="theme_preset"][value="modern"]').check();
  await expect(page.locator('[name="theme_font"]')).toHaveValue('system');
});

test('independent appearance choices persist and apply to the storefront', async ({ page, browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating store settings run once.');
  await loginAdmin(page);
  await page.goto('/admin/appearance/storefront');
  const names = ['header_layout', 'header_nav_style', 'display_cart_effect', 'display_product_sections'];
  const values = ['wide_search', 'underline', 'pulse', 'editorial'];
  const previous = await Promise.all(names.map(name => page.locator(`select[name="${name}"]`).inputValue()));
  const save = async (selected: string[]) => {
    for (let i = 0; i < names.length; i++) {
      await page.locator(`select[name="${names[i]}"]`).selectOption(selected[i]);
    }
    await Promise.all([
      page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/admin/appearance/storefront')),
      page.locator('form[data-theme-presets-json] button[type="submit"]').last().click(),
    ]);
  };
  const ctx = await browser.newContext({ baseURL: testInfo.project.use.baseURL, locale: 'uk-UA', viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  try {
    await save(values);
    const shop = await ctx.newPage();
    await shop.goto('/');
    const body = shop.locator('body');
    await expect(body).toHaveAttribute('data-header-layout', 'wide_search');
    await expect(body).toHaveAttribute('data-nav-style', 'underline');
    await expect(body).toHaveAttribute('data-cart-effect', 'pulse');
    await expect(body).toHaveAttribute('data-product-sections', 'editorial');
    await expect(shop.locator('link[href*="appearanceOptionsCss"], link[href*="appearance-options.css"]')).toHaveCount(1);
    const links = shop.locator('.reference-nav-item > a[href^="/"]:not([href^="//"])');
    await expect(links.first()).toBeVisible();
    const href = await links.first().getAttribute('href');
    expect(href).toBeTruthy();
    await shop.goto(href!);
    const active = shop.locator('.reference-nav-item > a[aria-current="page"]').first();
    await expect(active).toBeVisible();
    await expect.poll(() => active.evaluate(link => getComputedStyle(link, '::after').transform)).toBe('matrix(1, 0, 0, 1, 0, 0)');
  } finally {
    await ctx.close();
    await page.goto('/admin/appearance/storefront');
    await save(previous);
  }
});
