import { expect, test } from '@playwright/test';
import { expectNoHorizontalOverflow, expectNoServerError } from './helpers';

// Release 3.23.0 storefront chrome: header switchers, mega menu, cookie banner, floating widgets, stars, footer.

test.describe('storefront chrome 3.23', () => {
  test('footer no longer prints the locale and currency text', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    const footer = await page.locator('.site-footer').innerText();
    expect(footer).not.toMatch(/uk-UA\s*[·•]\s*UAH/);
    await expect(page.locator('.store-switchers select')).toHaveCount(0);
  });

  test('switchers are compact dropdowns shown only when there is a choice', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    for (const [wrap, option] of [['[data-lang-switch]', '[data-lang-option]'], ['[data-currency-switch]', '[data-currency-option]']] as const) {
      const sw = page.locator(wrap);
      const count = await sw.count();
      if (count === 0) continue; // single option: nothing to switch, so nothing rendered
      expect(await sw.locator(option).count()).toBeGreaterThanOrEqual(2);
      await expect(sw.locator('button[type="submit"]')).toHaveCount(0);
    }
  });

  test('language switch keeps the current page and persists the choice', async ({ page }) => {
    await page.goto('/cookie-policy', { waitUntil: 'domcontentloaded' });
    const other = page.locator('[data-lang-switch] [data-lang-option]:not(.is-current)').first();
    test.skip((await other.count()) === 0, 'only one storefront language is enabled');
    const code = await other.getAttribute('data-lang-option');
    await page.locator('[data-lang-switch] summary').click();
    await other.click();
    await page.waitForLoadState('domcontentloaded');
    await expectNoServerError(page);
    await expect(page.locator('html')).toHaveAttribute('lang', code!);
    const cookies = await page.context().cookies();
    expect(cookies.find((c) => c.name === 'store_locale')?.value).toBe(code);
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('html')).toHaveAttribute('lang', code!);
  });

  test('currency switch persists the selected currency', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const other = page.locator('[data-currency-switch] [data-currency-option]:not(.is-current)').first();
    test.skip((await other.count()) === 0, 'only one storefront currency is priced');
    const code = await other.getAttribute('data-currency-option');
    await page.locator('[data-currency-switch] summary').click();
    await other.click();
    await page.waitForLoadState('domcontentloaded');
    await expectNoServerError(page);
    expect((await page.context().cookies()).find((c) => c.name === 'store_currency')?.value).toBe(code);
    await expect(page.locator('[data-currency-switch] summary')).toContainText(code!);
  });

  test('catalog mega menu opens, lists categories and closes with Escape', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const toggle = page.locator('[data-mega-toggle]').first();
    const panel = page.locator('#mega-panel');
    await expect(panel).toBeHidden();
    await toggle.focus();
    await page.keyboard.press('Enter');
    await expect(panel).toBeVisible();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    expect(await panel.locator('a').count()).toBeGreaterThan(3);
    await expectNoHorizontalOverflow(page);
    await page.keyboard.press('Escape');
    await expect(panel).toBeHidden();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  });

  test('mobile mega menu is a drawer', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 800 });
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.locator('[data-mega-toggle]').first().click();
    const panel = page.locator('#mega-panel');
    await expect(panel).toBeVisible();
    await expectNoHorizontalOverflow(page);
    await page.keyboard.press('Escape');
    await expect(panel).toBeHidden();
  });

  test('cookie banner has a primary accept button and a floating settings icon', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const banner = page.locator('[data-commerce-consent]');
    await expect(banner).toBeVisible();
    await expect(banner.locator('.mc-consent__icon, svg').first()).toBeVisible();
    await expect(banner.locator('[data-consent-accept-all]')).toHaveClass(/button--primary/);
    const fab = page.locator('[data-consent-fab]');
    await expect(fab).toBeHidden();
    await banner.locator('[data-consent-accept-all]').click();
    await expect(banner).toBeHidden();
    await expect(fab).toBeVisible();
    await fab.click();
    await expect(banner).toBeVisible();
  });

  test('back-to-top sits on the right edge', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(800);
    const btn = page.locator('.back-to-top');
    await expect(btn).toBeVisible();
    const box = (await btn.boundingBox())!;
    expect(box.x + box.width / 2).toBeGreaterThan(720);
    const cw = page.locator('.cw');
    if (await cw.count()) {
      const c = await cw.first().boundingBox();
      if (c) expect(box.y + box.height <= c.y + 1 || box.x + box.width <= c.x + 1 || box.x >= c.x + c.width - 1).toBe(true);
    }
  });

  test('rating stars use the gold token', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const color = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--mc-color-star').trim().toLowerCase());
    expect(color).toBe('#f5b301');
    const star = page.locator('.rating-stars').first();
    if (await star.count()) {
      expect(await star.evaluate((el) => getComputedStyle(el).color)).toBe('rgb(245, 179, 1)');
    }
  });

  test('newsletter block keeps form and copy on one row on desktop', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const block = page.locator('.newsletter-signup').first();
    await block.scrollIntoViewIfNeeded();
    const form = await block.locator('form').boundingBox();
    const copy = await block.locator('h2, h3').first().boundingBox();
    expect(form && copy).toBeTruthy();
    expect(form!.x).toBeGreaterThan(copy!.x + copy!.width - 1);
  });
});
