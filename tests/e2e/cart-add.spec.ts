import { expect, test } from '@playwright/test';

test('in-stock product can be added to the cart', async ({ page }) => {
  const productResponse = await page.goto('/apple-macbook-pro-14-inch-space-grey', { waitUntil: 'domcontentloaded' });
  expect(productResponse?.status() ?? 0).toBeLessThan(500);

  const form = page.locator('form[data-buy-actions]');
  await expect(form).toBeVisible();

  const addResponsePromise = page.waitForResponse((response) =>
    response.url().endsWith('/cart/add') && response.request().method() === 'POST'
  );
  await form.locator('[data-primary-buy]').click();
  const addResponse = await addResponsePromise;

  expect(addResponse.status()).toBeLessThan(500);
  const payload = await addResponse.json();
  expect(payload.ok).toBe(true);
  expect(Number(payload.cart?.count ?? 0)).toBeGreaterThan(0);

  const cartResponse = await page.goto('/cart', { waitUntil: 'domcontentloaded' });
  expect(cartResponse?.status() ?? 0).toBeLessThan(500);
  await expect(page.locator('body')).toContainText('Apple MacBook Pro 14 Inch Space Grey');
});
