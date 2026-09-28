import { expect, test } from '@playwright/test';
import { expectNoServerError } from './helpers';

test('an in-stock catalog product can be added directly from the catalog card', async ({ page }) => {
  const catalogResponse = await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  expect(catalogResponse?.status() ?? 0).toBeLessThan(500);
  await expectNoServerError(page);

  const card = page.locator('[data-product-card]').filter({
    has: page.locator('form[data-card-add-to-cart]'),
  }).first();
  await expect(card).toBeVisible();

  const productName = (await card.locator('h2 a').innerText()).trim();
  expect(productName).not.toBe('');

  const addResponsePromise = page.waitForResponse((response) =>
    response.url().endsWith('/cart/add') && response.request().method() === 'POST'
  );
  await card.locator('form[data-card-add-to-cart] button[type="submit"]').click();
  const addResponse = await addResponsePromise;

  expect(addResponse.status()).toBeLessThan(500);
  const payload = await addResponse.json();
  expect(payload.ok).toBe(true);
  expect(Number(payload.cart?.count ?? 0)).toBeGreaterThan(0);

  await expect(page.locator('[data-cart-count]')).toContainText(String(payload.cart.count));

  const cartResponse = await page.goto('/cart', { waitUntil: 'domcontentloaded' });
  expect(cartResponse?.status() ?? 0).toBeLessThan(500);
  await expectNoServerError(page);
  await expect(page.locator('body')).toContainText(productName);
});

test('an in-stock product can be added from its product page', async ({ page }) => {
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const card = page.locator('[data-product-card]').filter({
    has: page.locator('form[data-card-add-to-cart]'),
  }).first();
  await expect(card).toBeVisible();

  const productName = (await card.locator('h2 a').innerText()).trim();
  const productUrl = await card.locator('h2 a').getAttribute('href');
  expect(productName).not.toBe('');
  expect(productUrl).toBeTruthy();

  await page.goto(productUrl!, { waitUntil: 'domcontentloaded' });
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

  await page.goto('/cart', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('body')).toContainText(productName);
});
