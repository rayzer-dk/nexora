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
  await expect(page.locator('html')).toHaveAttribute('data-cart-drawer-ready', '1');
  let releaseRequest: () => void = () => {};
  const gate = new Promise<void>((resolve) => { releaseRequest = resolve; });
  await page.route('**/cart/drawer', async (route) => {
    await gate;
    await route.continue();
  });
  const click = page.locator('[data-cart-open]:visible').first().click({ noWaitAfter: true });
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
  const openChoices = async () => {
    if (await page.locator('[data-commerce-consent]').isVisible()) {
      await page.locator('[data-consent-customize]').click();
    } else {
      await page.locator('[data-consent-open]').first().click();
    }
  };
  await openChoices();
  await expect(control).toBeVisible();
  await control.selectOption('off');
  await expect(page.locator('html')).toHaveAttribute('data-motion', 'off');
  await page.reload({ waitUntil: 'domcontentloaded' });
  await openChoices();
  await expect(control).toHaveValue('off');
  await expect(page.locator('html')).toHaveAttribute('data-motion', 'off');
  await control.selectOption('full');
});

test('quick product preview animates unless reduced motion is enabled', async ({ page }) => {
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const open = page.locator('[data-quick-view]').first();
  test.skip(await open.count() === 0, 'This demo catalog has no quick-view buttons.');
  await page.emulateMedia({ reducedMotion: 'no-preference' });
  await open.click();
  const dialog = page.locator('.quick-view__dialog');
  await expect(dialog).toBeVisible();
  expect(await dialog.evaluate((node) => getComputedStyle(node).animationName)).toContain('mc-motion-reveal');
  await page.emulateMedia({ reducedMotion: 'reduce' });
  expect(await dialog.evaluate((node) => getComputedStyle(node).animationName)).toBe('none');
});

test('catalog pagination shows skeleton cards while its request is blocked', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'The network-gated pagination test runs once.');
  await page.goto('/catalog?per_page=1', { waitUntil: 'domcontentloaded' });
  const next = page.locator('nav.pager a[href]').last();
  test.skip(await next.count() === 0, 'Catalog has only one page.');
  let release: () => void = () => {};
  const gate = new Promise<void>((resolve) => { release = resolve; });
  await page.route('**/*', async (route) => {
    if (route.request().resourceType() === 'fetch' &&
      route.request().headers()['x-requested-with'] === 'XMLHttpRequest') {
      await gate;
    }
    await route.continue();
  });
  try {
    await next.evaluate((node: HTMLAnchorElement) => node.click());
    await expect(page.locator('.mc-motion-catalog-skeleton__card')).toHaveCount(6);
  } finally {
    release();
  }
  await expect(page.locator('.mc-motion-catalog-skeleton')).toHaveCount(0);
  await expect(page.locator('.product-grid')).toBeVisible();
});

test('admin user menu exposes a persisted motion preference', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Admin UI preference is verified once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin login is required.');
  const { loginAdmin } = await import('./helpers');
  await loginAdmin(page);
  await page.goto('/admin', { waitUntil: 'domcontentloaded' });
  await page.locator('[data-user-menu] > summary').click();
  const picker = page.locator('#admin-motion-preference');
  await expect(picker).toBeVisible();
  await picker.selectOption('minimal');
  await expect(page.locator('html')).toHaveAttribute('data-motion', 'minimal');
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.locator('html')).toHaveAttribute('data-motion', 'minimal');
  await page.evaluate(() => localStorage.removeItem('nx_motion_preference'));
});

test('card lift is only applied to pointer devices and respects reduced motion', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'no-preference' });
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const canHover = await page.evaluate(() => matchMedia('(hover: hover) and (pointer: fine)').matches);
  test.skip(!canHover, 'Hover microinteractions are intentionally disabled on touch devices.');
  const card = page.locator('.catalog-card').first();
  await card.hover();
  await expect(card).not.toHaveCSS('transform', 'none');
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await expect(card).toHaveCSS('transform', 'none');
});

test('user-triggered toasts include a real countdown indicator', async ({ page }) => {
  // The global browser fixture enables reduced motion: explicitly opt in for this positive assertion.
  await page.emulateMedia({ reducedMotion: 'no-preference' });
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('html')).toHaveAttribute('data-cart-drawer-ready', '1');
  await page.evaluate(() => {
    document.dispatchEvent(new CustomEvent('mc:toast', { detail: { message: 'Motion toast test', type: 'success' } }));
  });
  const item = page.locator('.storefront-toast', { hasText: 'Motion toast test' });
  await expect(item).toBeVisible();
  await expect(item.locator('.mc-toast-countdown')).toBeVisible();
  await page.evaluate(() => { document.documentElement.dataset.motion = 'off'; });
  await page.evaluate(() => {
    document.dispatchEvent(new CustomEvent('mc:toast', { detail: { message: 'Motion is off', type: 'success' } }));
  });
  const plain = page.locator('.storefront-toast', { hasText: 'Motion is off' });
  await expect(plain).toBeVisible();
  await expect(plain.locator('.mc-toast-countdown')).toHaveCount(0);

  await page.evaluate(() => { document.documentElement.dataset.motion = 'full'; });
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.evaluate(() => {
    document.dispatchEvent(new CustomEvent('mc:toast', { detail: { message: 'System prefers reduced motion', type: 'success' } }));
  });
  const reduced = page.locator('.storefront-toast', { hasText: 'System prefers reduced motion' });
  await expect(reduced).toBeVisible();
  await expect(reduced.locator('.mc-toast-countdown')).toHaveCount(0);
});
