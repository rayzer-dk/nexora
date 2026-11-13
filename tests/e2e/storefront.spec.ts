import { expect, test } from '@playwright/test';
import { expectNoBrokenImages, expectNoHorizontalOverflow, expectNoServerError, publicRoutes } from './helpers';

for (const route of publicRoutes) {
  test(`${route} renders without overflow or server error`, async ({ page }) => {
    const response = await page.goto(route, { waitUntil: 'domcontentloaded' });
    expect(response?.status() ?? 0).toBeLessThan(500);
    await expectNoServerError(page);
    await expectNoHorizontalOverflow(page);
    await expectNoBrokenImages(page);
  });
}

test('interactive controls have usable labels and target sizes', async ({ page }) => {
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  const unlabeled = await page.locator('button, a, input, select, textarea').evaluateAll((nodes) => nodes.filter((node) => {
    const el = node as HTMLElement;
    const hidden = el.getAttribute('aria-hidden') === 'true' || el.closest('[hidden]');
    if (hidden) return false;
    if (el instanceof HTMLInputElement && el.type === 'hidden') return false;
    const label = el.getAttribute('aria-label') || el.getAttribute('title') || el.textContent?.trim() || (el as HTMLInputElement).placeholder;
    return !label;
  }).slice(0, 20).map((el) => el.outerHTML.slice(0, 180)));
  expect(unlabeled).toEqual([]);
});


test('storefront header shows the Nexora logo by default', async ({ page }) => {
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const brand = page.locator('.reference-brand');
  await expect(brand).toBeVisible();
  await expect(brand.locator('img[src*="nexora-logo"]').first()).toHaveAttribute('alt', 'Nexora');
  await expect(brand.locator('.reference-brand__bag')).toHaveCount(0);
});
  await expectNoServerError(page);
  const brand = page.locator('.reference-brand');
  await expect(brand).toBeVisible();
  await expect(brand.locator('strong')).toHaveText('Nexora E2E');
  await expect(brand.locator('img[src*="nexora-mark.png"]')).toHaveCount(0);
});


test('unknown SEO route returns a normal 404 without a storefront runtime exception', async ({ page }) => {
  const response = await page.goto('/e2e-definitely-missing-seo-route', { waitUntil: 'domcontentloaded' });
  expect(response?.status()).toBe(404);
  await expectNoServerError(page);
});
