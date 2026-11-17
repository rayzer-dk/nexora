import { test, expect } from '@playwright/test';

const routes: Array<[string, string]> = [
  ['/', 'home'],
  ['/catalog', 'catalog'],
  ['/cart', 'cart'],
  ['/admin/login', 'admin-login'],
];

for (const [route, name] of routes) {
  test(`${name} visual regression`, async ({ page }) => {
    test.skip(process.env.E2E_VISUAL !== '1', 'Visual snapshots run only when E2E_VISUAL=1');
    await page.goto(route, { waitUntil: 'networkidle' });
    // Capture only after web fonts are ready; CSS transitions and caret are disabled below.
    await page.evaluate(() => document.fonts.ready);
    // The Safari-only install CTA is asserted by PWA tests; keep existing page
    // baselines stable independently of browser install eligibility.
    await page.locator('[data-pwa-install], [data-pwa-install-help]').evaluateAll((nodes) => {
      nodes.forEach((node) => { (node as HTMLElement).hidden = true; });
    });
    await expect(page).toHaveScreenshot(`${name}.png`, {
      fullPage: true,
      animations: 'disabled',
      caret: 'hide',
      maxDiffPixelRatio: 0.005,
    });
  });
}
