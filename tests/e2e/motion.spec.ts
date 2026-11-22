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
  let releaseRequest: () => void = () => {};
  const gate = new Promise<void>((resolve) => { releaseRequest = resolve; });
  await page.route('**/cart/drawer', async (route) => {
    await gate;
    await route.continue();
  });
  const click = page.locator('[data-cart-open]').first().click({ noWaitAfter: true });
  const skeleton = page.locator('.mc-motion-cart-skeleton__row').first();
  try {
    await expect(skeleton).toBeVisible();
  } finally {
    releaseRequest();
  }
  await click;
  await expect(skeleton).toBeHidden({ timeout: 10000 });
  await expect(page.locator('[data-cart-drawer]')).toBeVisible();
});


test('motion preference is localized, stored and applied on later page loads', async ({ page }) => {
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const control = page.locator('[data-motion-select]');
  await expect(control).toBeVisible();
  await control.selectOption('off');
  await expect(page.locator('html')).toHaveAttribute('data-motion', 'off');
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(control).toHaveValue('off');
  await expect(page.locator('html')).toHaveAttribute('data-motion', 'off');
  await control.selectOption('full');
});
