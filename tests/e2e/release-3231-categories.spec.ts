import { expect, test } from '@playwright/test';
import { expectNoServerError } from './helpers';

// Release 3.23.1: every category in the mega menu opens (desktop and phone) and shows no server error.
for (const [label, viewport] of [['desktop', { width: 1366, height: 800 }], ['phone', { width: 390, height: 800 }]] as const) {
  test(`mega-menu categories open on ${label}`, async ({ browser }) => {
    const page = await (await browser.newContext({ viewport })).newPage();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.locator('[data-mega-toggle]:visible').first().click();
    const hrefs = await page.locator('.mega__cat--top').evaluateAll((a) => a.map((x) => x.getAttribute('href') ?? ''));
    expect(hrefs.length).toBeGreaterThan(0);
    for (const href of hrefs) {
      const response = await page.goto(href, { waitUntil: 'domcontentloaded' });
      expect(response?.status(), href).toBe(200);
      await expectNoServerError(page);
      await expect(page.locator('h1').first()).toBeVisible();
    }
    await page.context().close();
  });
}
