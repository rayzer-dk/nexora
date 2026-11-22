import { expect, test } from '@playwright/test';

test('catalog cards respect system reduced motion and explicit off mode', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'no-preference' });
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const img = page.locator('.catalog-card__media img').first();
  await expect(img).toBeVisible();
  const hasHover = await page.evaluate(() => matchMedia('(hover: hover) and (pointer: fine)').matches);
  const normal = await img.evaluate((e) => getComputedStyle(e).transitionDuration);
  expect(normal).toBe(hasHover ? '0.34s' : '0s');
  await page.emulateMedia({ reducedMotion: 'reduce' });
  expect(await img.evaluate((e) => getComputedStyle(e).transitionDuration)).toBe('0s');
  await page.emulateMedia({ reducedMotion: 'no-preference' });
  await page.evaluate(() => { document.documentElement.dataset.motion = 'off'; });
  expect(await img.evaluate((e) => getComputedStyle(e).transitionDuration)).toBe('0s');
  await expect(img).toBeVisible();
});

test('cart skeleton exists only while its request is in flight', async ({ page }) => {
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  await page.route('**/cart/drawer', async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 550));
    await route.continue();
  });
  await page.locator('[data-cart-open]').first().click();
  const skeleton = page.locator('.mc-motion-cart-skeleton__row').first();
  await expect(skeleton).toBeVisible();
  await expect(skeleton).toBeHidden({ timeout: 10000 });
  await expect(page.locator('[data-cart-drawer]')).toBeVisible();
});
