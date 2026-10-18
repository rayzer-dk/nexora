import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0 });

const shots = process.env.T05_SHOTS ?? '';

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL(/\/admin(?:\/(?!login)|$)/),
    page.locator('button[type="submit"]').click(),
  ]);
  await expectNoServerError(page);
}

async function setTheme(page: Page, theme: 'light' | 'dark'): Promise<void> {
  await page.evaluate((value) => {
    localStorage.setItem('mc_admin_theme', value);
    document.documentElement.dataset.theme = value;
  }, theme);
}

async function shot(page: Page, name: string, theme: string): Promise<void> {
  if (shots === '') return;
  const width = page.viewportSize()?.width ?? 0;
  await page.screenshot({ path: `${shots}/${name}-${width}-${theme}.png`, fullPage: false });
}

/** WCAG contrast of the text colour against the first opaque background up the tree. */
async function contrastOf(page: Page, selector: string): Promise<number> {
  return page.evaluate((sel) => {
    const parse = (value: string): number[] => (value.match(/[\d.]+/g) ?? []).map(Number);
    const lum = (rgb: number[]): number => {
      const [r, g, b] = rgb.slice(0, 3).map((c) => { const v = c / 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; });
      return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };
    const element = document.querySelector(sel)!;
    const color = parse(getComputedStyle(element).color);
    let node: Element | null = element;
    let background = [255, 255, 255];
    while (node) {
      const bg = parse(getComputedStyle(node).backgroundColor);
      if (bg.length >= 3 && (bg.length === 3 || bg[3] === 1)) { background = bg; break; }
      node = node.parentElement;
    }
    const [a, b] = [lum(color), lum(background)].sort((x, y) => y - x);
    return (a + 0.05) / (b + 0.05);
  }, selector);
}

test.beforeEach(({}, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once against the audit copy.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');
});

for (const width of [1440, 390]) {
  test(`admin round 5 screens at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await loginAdmin(page);
    for (const theme of ['light', 'dark'] as const) {
      await setTheme(page, theme);
      for (const [name, url] of [
        ['dashboard', '/admin'],
        ['localization', '/admin/system/localization'],
        ['marketplaces', '/admin/system/integrations/connections/marketplaces'],
        ['payments', '/admin/system/integrations/connections/payments'],
        ['ai', '/admin/system/ai'],
      ] as const) {
        await page.goto(url, { waitUntil: 'domcontentloaded' });
        await expectNoServerError(page);
        await shot(page, name, theme);
      }

      await page.goto('/admin', { waitUntil: 'domcontentloaded' });
      for (const selector of ['.admin-topbar [data-cache-menu] > summary', '.admin-topbar .admin-context label']) {
        const target = page.locator(selector).first();
        if (await target.isVisible()) expect(await contrastOf(page, selector), `${selector} ${theme}`).toBeGreaterThanOrEqual(4.5);
      }

      await page.goto('/admin/system/localization', { waitUntil: 'domcontentloaded' });
      for (const label of await page.locator('.admin-rate-form label').all()) {
        if (!(await label.isVisible())) continue;
        const box = await label.boundingBox();
        const lineHeight = await label.evaluate((el) => parseFloat(getComputedStyle(el).lineHeight) || 20);
        expect(box!.height, 'currency code stays on the field line').toBeLessThan(lineHeight * 2.2);
      }

      await page.goto('/admin/system/ai', { waitUntil: 'domcontentloaded' });
      const toggle = page.locator('[data-secret-toggle]').first();
      const keyInput = page.locator(await toggle.getAttribute('data-secret-toggle') ?? '').first();
      await toggle.click();
      await expect(keyInput).toHaveAttribute('type', 'text');
      await toggle.click();
      await expect(keyInput).toHaveAttribute('type', 'password');
    }
  });
}
