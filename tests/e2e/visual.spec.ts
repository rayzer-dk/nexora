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
    await expect(page).toHaveScreenshot(`${name}.png`, {
      fullPage: true,
      animations: 'disabled',
      caret: 'hide',
      maxDiffPixelRatio: 0.005,
    });
  });
}
