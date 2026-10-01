import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

// Releases 3.24.0 / 3.26.0: the original stays in its folder, named sizes are made on demand into a separate cache, the main photo at once.

const VARIANT = /\/media\/cache\/(thumb|card|product|zoom)-g(\d+)\/[\w/-]+\.(webp|avif|jpg)$/;

async function firstBuyableCard(page: Page) {
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  return page.locator('[data-product-card]').filter({ has: page.locator('img[srcset]') }).first();
}

test('catalog cards use the card size with a srcset of named sizes that really load', async ({ page, request }) => {
  const card = await firstBuyableCard(page);
  const img = card.locator('img').first();
  const src = (await img.getAttribute('src')) ?? '';
  expect(src).toMatch(VARIANT);
  expect(src).toContain('/cache/card-g');
  const srcset = (await img.getAttribute('srcset')) ?? '';
  expect(srcset).toMatch(/cache\/thumb-g\d+\/.*\.\w+ 160w, .*cache\/card-g\d+\/.*\.\w+ 480w, .*cache\/product-g\d+\/.*\.\w+ 960w/);
  expect(await img.getAttribute('sizes')).toBeTruthy();
  for (const part of srcset.split(',')) {
    const url = part.trim().split(' ')[0];
    const res = await request.get(url);
    expect(res.status(), url).toBe(200);
    expect(res.headers()['content-type']).toMatch(/^image\//);
  }
  await img.scrollIntoViewIfNeeded();
  await expect.poll(() => img.evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth > 0)).toBe(true);
});

test('the product page serves product and zoom sizes, small thumbs and the zoom size in the full-screen viewer', async ({ page }) => {
  const card = await firstBuyableCard(page);
  await card.locator('a.catalog-card__media').click();
  await page.waitForLoadState('domcontentloaded');
  const main = page.locator('[data-gallery-main]');
  expect((await main.getAttribute('src')) ?? '').toContain('/cache/product-g');
  expect((await main.getAttribute('srcset')) ?? '').toMatch(/cache\/product-g\d+\/.*\.\w+ 960w, .*cache\/zoom-g\d+\/.*\.\w+ 1600w/);
  const thumbs = page.locator('[data-gallery-thumb]');
  if ((await thumbs.count()) > 1) {
    for (const t of await thumbs.all()) {
      expect((await t.locator('img').getAttribute('src')) ?? '').toContain('/cache/thumb-g');
      expect((await t.getAttribute('data-full')) ?? '').toContain('/cache/zoom-g');
    }
  }
  await page.locator('[data-gallery-open]').click();
  const full = page.locator('[data-gallery-dialog-image]');
  await expect(full).toBeVisible();
  expect((await full.getAttribute('src')) ?? '').toContain('/cache/zoom-g');
  await expect.poll(() => full.evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth > 0)).toBe(true);
});

test('only the closed set of sizes can be requested', async ({ request, page }) => {
  const card = await firstBuyableCard(page);
  const src = (await card.locator('img').first().getAttribute('src')) ?? '';
  const [, stem] = src.match(/^\/media\/cache\/card-g\d+\/([\w/-]+)\.\w+$/) ?? [];
  expect(stem).toBeTruthy();
  const ext = src.split('.').pop();
  for (const bad of [`/media/cache/huge-g1/${stem}.${ext}`, `/media/cache/card-g1/${stem}.php`, `/media/cache/card-g1/../etc/passwd.${ext}`, `/media/cache/card-g9999/${stem}.${ext}`, `/media/cache/card-g1/${stem}.png`]) {
    const res = await request.get(bad, { maxRedirects: 0 });
    expect(res.status(), bad).toBe(404);
  }
  const old = await request.get(`/media/cache/card-g0/${stem}.${ext}`, { maxRedirects: 0 });
  expect(old.status()).toBe(302);
  expect(old.headers().location ?? '').toMatch(VARIANT);
  const follow = await request.get(`/media/cache/card-g0/${stem}.${ext}`);
  expect(follow.status(), 'an older generation leads to the current file').toBe(200);
});

test('admin: the four sizes are editable and saving keeps the generation when nothing changed', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once on desktop (admin sign-in is rate limited).');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/|$)/), page.locator('button[type="submit"]').click()]);

  await page.goto('/admin/media', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  for (const name of ['thumb', 'card', 'product', 'zoom']) await expect(page.locator(`select[name="presets[${name}]"]`)).toHaveCount(1);
  await expect(page.locator('input[name="widths[]"], input[name="include_original"], input[name="jpeg_fallback"], input[name="keep_source"]')).toHaveCount(0);
  await expect(page.locator('select[name="format"] option')).toHaveCount(3);
  await expect(page.locator('.media-processing-summary')).toContainText('2560');

  const generation = async (): Promise<number> => {
    await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
    const src = (await page.locator('[data-product-card] img[srcset]').first().getAttribute('src')) ?? '';
    return Number(src.match(/-g(\d+)\./)?.[1] ?? 0);
  };
  const before = await generation();
  await page.goto('/admin/media', { waitUntil: 'domcontentloaded' });
  await Promise.all([page.waitForURL(/\/admin\/media/), page.locator('.media-processing-form button[type="submit"]').click()]);
  await expectNoServerError(page);
  expect(await generation()).toBe(before);
});
