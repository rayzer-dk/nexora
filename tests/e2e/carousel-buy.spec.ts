import { expect, test } from '@playwright/test';
import { expectNoServerError } from './helpers';

test('similar products carry their own add-to-cart button that fills the cart', async ({ page }) => {
  await page.goto('/iphone-x', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const tile = page.locator('.related-card').first();
  await expect(tile).toBeVisible();
  const buy = tile.locator('.catalog-card__buy');
  await expect(buy).toBeVisible();
  const before = Number((await page.locator('[data-cart-count]').first().textContent())?.trim() || '0');
  const response = page.waitForResponse((r) => r.url().endsWith('/cart/add') && r.request().method() === 'POST');
  await buy.click();
  expect((await response).status()).toBeLessThan(400);
  await expect.poll(async () => Number((await page.locator('[data-cart-count]').first().textContent())?.trim() || '0')).toBeGreaterThan(before);
});

test('the gallery stage lets horizontal swipes through to the page script', async ({ page }) => {
  await page.goto('/iphone-x', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.product-gallery__stage').first()).toHaveCSS('touch-action', 'pan-y pinch-zoom');
});
