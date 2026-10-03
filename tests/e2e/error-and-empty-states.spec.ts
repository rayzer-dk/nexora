import { expect, test } from '@playwright/test';

// Empty and error states of the storefront: they must fit the screen and keep their scripts (the error page once
// printed an empty CSP nonce, so the browser blocked every inline script on it).
const states = [
  { path: '/no-such-page-here', status: 404 },
  { path: '/catalog/no-such-category', status: 404 },
  { path: '/product/no-such-product', status: 404 },
  { path: '/catalog?q=zzzzqqqq', status: 200 },
  { path: '/cart', status: 200 },
  { path: '/account/login', status: 200 },
];

for (const width of [390, 768, 1440]) {
  test(`empty and error states fit a ${width}px screen and run their scripts`, async ({ browser }, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium-desktop', 'The viewport is set by the test itself.');
    const context = await browser.newContext({ viewport: { width, height: 900 }, baseURL: testInfo.project.use.baseURL });
    const page = await context.newPage();
    const violations: string[] = [];
    page.on('console', (message) => {
      if (/Content Security Policy/i.test(message.text())) violations.push(message.text().slice(0, 140));
    });
    for (const state of states) {
      const response = await page.goto(state.path, { waitUntil: 'load' });
      expect(response?.status(), state.path).toBe(state.status);
      await expect(page.locator('h1').first(), state.path).toBeVisible();
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      expect(overflow, `${state.path} scrolls sideways`).toBeLessThanOrEqual(0);
    }
    expect(violations).toEqual([]);
    await context.close();
  });
}
