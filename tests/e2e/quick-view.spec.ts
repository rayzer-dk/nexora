import { expect, test } from '@playwright/test';

// Release 3.97.24: quick view of a product card on the storefront.

test('a product card opens in a quick view, adds to the cart and closes with Escape', async ({ page }) => {
  page.setDefaultNavigationTimeout(120_000);
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const card = page.locator('[data-product-card]:has(form[data-card-add-to-cart])').first();
  await expect(card).toBeVisible();
  const name = (await card.locator('.catalog-card__body h2 a').innerText()).trim();
  const href = await card.locator('.catalog-card__body h2 a').getAttribute('href');
  await card.hover();
  await card.locator('[data-quick-view]').click();

  const dialog = page.locator('[data-quick-view-dialog] [role="dialog"]');
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('h2')).toHaveText(name);
  await expect(dialog.locator('.catalog-card__price')).toBeVisible();
  expect(((await dialog.locator('a.quick-view__full').getAttribute('href')) ?? '').endsWith(String(href))).toBe(true);
  // the description comes from the product page
  await expect(dialog.locator('.quick-view__text')).not.toBeEmpty({ timeout: 15_000 });

  // Escape closes it and gives the focus back
  await page.keyboard.press('Escape');
  await expect(page.locator('[data-quick-view-dialog]')).toHaveCount(0);

  // buying from the quick view puts the product in the cart
  await card.hover();
  await card.locator('[data-quick-view]').click();
  const buy = page.locator('[data-quick-view-dialog] form[data-card-add-to-cart] button[type="submit"]');
  await expect(buy).toBeVisible();
  const [response] = await Promise.all([page.waitForResponse((r) => r.url().endsWith('/cart/add') && r.request().method() === 'POST'), buy.click()]);
  expect(response.ok()).toBe(true);
  expect((await response.json()).ok).toBe(true);
  await expect(page.locator('[data-quick-view-dialog]')).toHaveCount(0);
});
