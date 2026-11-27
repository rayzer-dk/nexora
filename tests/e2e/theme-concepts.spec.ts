import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { expectNoServerError, loginAdmin } from './helpers';

const concepts = ['modern', 'marketplace', 'premium', 'minimal', 'soft', 'dynamic', 'green'];
const names = [
  'theme_preset', 'theme_concept', 'theme_primary', 'theme_accent', 'theme_success',
  'theme_surface', 'theme_radius', 'theme_shadow', 'theme_density', 'theme_container', 'theme_font',
  'header_layout', 'header_nav_style', 'display_card_style', 'display_card_columns',
  'display_card_ratio', 'display_category_style', 'display_product_layout', 'display_product_details',
  'display_product_sections', 'display_cart_effect', 'home_composition',
];

test('seven concepts render distinct storefront layouts at desktop and mobile widths', async ({ page, browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings and comparative captures run once.');
  test.setTimeout(600_000);
  await loginAdmin(page);
  await page.goto('/admin/appearance/storefront');
  const form = page.locator('form[data-theme-presets-json]');
  const original = await form.evaluate((node, keys) => {
    const data = new FormData(node as HTMLFormElement);
    return Object.fromEntries(keys.map(key => [key, String(data.get(key) ?? '')]));
  }, names);
  const save = async () => {
    const [response] = await Promise.all([
      page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/admin/appearance/storefront')),
      form.locator('button[type="submit"]').last().click(),
    ]);
    expect(response.status()).toBeLessThan(400);
    await page.waitForLoadState('domcontentloaded');
  };
  const context = await browser.newContext({ baseURL: testInfo.project.use.baseURL, locale: 'uk-UA', viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  const shop = await context.newPage();
  const signatures = new Set<string>();
  try {
    for (const code of concepts) {
      await page.goto('/admin/appearance/storefront');
      await form.locator(`input[name="theme_preset"][value="${code}"]`).check();
      await form.locator('[data-apply-theme-concept]').click();
      await expect(form.locator('[name="theme_concept"]')).toBeChecked();
      await save();
      await shop.setViewportSize({ width: 1440, height: 1000 });
      await shop.goto('/');
      await expectNoServerError(shop);
      await expect(shop.locator('body')).toHaveAttribute('data-theme-preset', code);
      await expect(shop.locator('body')).toHaveAttribute('data-theme-concept', '1');
      await expect(shop.locator('link[href*="themeConceptsCss"], link[href*="theme-concepts.css"]')).toHaveCount(1);
      await shop.evaluate(() => document.fonts.ready);
      signatures.add(await shop.evaluate(() => {
        const body = document.body;
        const header = document.querySelector('.reference-header__main');
        return JSON.stringify([getComputedStyle(body).backgroundColor, header ? getComputedStyle(header).gridTemplateColumns : '', body.dataset.cardStyle, body.dataset.cardRatio, body.dataset.productLayout, body.dataset.navStyle]);
      }));
      const lightContrast = await new AxeBuilder({ page: shop }).withRules(['color-contrast']).analyze();
      expect.soft(lightContrast.violations.map(v => v.nodes.map(n => n.target)), `${code}: light contrast`).toEqual([]);
      for (const zoom of [0.75, 1.5]) {
        await shop.evaluate(value => { document.documentElement.style.zoom = String(value); }, zoom);
        await expect.soft.poll(() => shop.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2), { message: `${code}: zoom ${zoom}` }).toBe(true);
      }
      await shop.evaluate(() => { document.documentElement.style.removeProperty('zoom'); });
      await testInfo.attach(`${code}-desktop-home`, { body: await shop.screenshot({ animations: 'disabled' }), contentType: 'image/png' });
      const toggle = shop.locator('[data-mega-toggle]');
      if (await toggle.isVisible()) {
        await toggle.click();
        await expect(shop.locator('[data-mega-panel]')).toBeVisible();
        await shop.keyboard.press('Escape');
        await expect(shop.locator('[data-mega-panel]')).toBeHidden();
      }
      await shop.evaluate(() => {
        localStorage.setItem('mc_theme', 'dark');
        document.documentElement.dataset.theme = 'dark';
      });
      await expect(shop.locator('html')).toHaveAttribute('data-theme', 'dark');
      const darkContrast = await new AxeBuilder({ page: shop }).withRules(['color-contrast']).analyze();
      expect.soft(darkContrast.violations.map(v => v.nodes.map(n => n.target)), `${code}: dark contrast`).toEqual([]);
      await testInfo.attach(`${code}-desktop-dark-home`, { body: await shop.screenshot({ animations: 'disabled' }), contentType: 'image/png' });
      await shop.evaluate(() => {
        localStorage.removeItem('mc_theme');
        delete document.documentElement.dataset.theme;
      });
      await shop.setViewportSize({ width: 768, height: 1024 });
      await expect.poll(() => shop.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
      await testInfo.attach(`${code}-tablet-home`, { body: await shop.screenshot({ animations: 'disabled' }), contentType: 'image/png' });
      await shop.setViewportSize({ width: 1440, height: 1000 });
      await shop.goto('/catalog');
      await expectNoServerError(shop);
      const card = shop.locator('[data-product-card]').first();
      await expect(card).toBeVisible();
      const href = await card.locator('h2 a').getAttribute('href');
      await testInfo.attach(`${code}-desktop-catalog`, { body: await shop.screenshot({ animations: 'disabled' }), contentType: 'image/png' });
      expect(href).toBeTruthy();
      await shop.goto(href!);
      await expectNoServerError(shop);
      await expect(shop.locator('.product-region--summary')).toBeVisible();
      await testInfo.attach(`${code}-desktop-product`, { body: await shop.screenshot({ animations: 'disabled' }), contentType: 'image/png' });
      await expect.poll(() => shop.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
      await shop.setViewportSize({ width: 320, height: 720 });
      await shop.goto('/');
      await expectNoServerError(shop);
      await expect.poll(() => shop.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
      await shop.setViewportSize({ width: 390, height: 844 });
      await shop.goto('/');
      await expectNoServerError(shop);
      await testInfo.attach(`${code}-mobile-home`, { body: await shop.screenshot({ animations: 'disabled' }), contentType: 'image/png' });
      await expect.poll(() => shop.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
      await shop.goto('/catalog');
      await expectNoServerError(shop);
      await expect.poll(() => shop.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
      await testInfo.attach(`${code}-mobile-catalog`, { body: await shop.screenshot({ animations: 'disabled' }), contentType: 'image/png' });
      await shop.goto(href!);
      await expectNoServerError(shop);
      await expect.poll(() => shop.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
      await testInfo.attach(`${code}-mobile-product`, { body: await shop.screenshot({ animations: 'disabled' }), contentType: 'image/png' });
    }
    expect(signatures.size).toBe(concepts.length);
  } finally {
    await context.close();
    await page.goto('/admin/appearance/storefront');
    await form.locator(`input[name="theme_preset"][value="${original.theme_preset}"]`).check();
    await form.evaluate((node, values) => {
      const formElement = node as HTMLFormElement;
      for (const [name, value] of Object.entries(values)) {
        if (name === 'theme_preset') continue;
        const field = formElement.elements.namedItem(name);
        const controls = field instanceof RadioNodeList ? Array.from(field) : field ? [field] : [];
        for (const control of controls) {
          if (control instanceof HTMLInputElement) {
            if (control.type === 'radio') control.checked = control.value === value;
            else if (control.type === 'checkbox') control.checked = value === '1';
            else control.value = value;
          } else if (control instanceof HTMLSelectElement) control.value = value;
          else continue;
          control.dispatchEvent(new Event('change', { bubbles: true }));
        }
      }
    }, original);
    await save();
  }
});
