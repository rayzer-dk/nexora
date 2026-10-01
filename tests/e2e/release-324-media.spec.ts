import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

// Release 3.24.0: one stored master per picture, named sizes made on demand, the main photo made at once.

const VARIANT = /\/media\/[\w/-]+\.(thumb|card|product|zoom)-g(\d+)\.(webp|avif|jpe?g|png)$/;

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
  expect(src).toContain('.card-g');
  const srcset = (await img.getAttribute('srcset')) ?? '';
  expect(srcset).toMatch(/\.thumb-g\d+\.\w+ 160w, .*\.card-g\d+\.\w+ 480w, .*\.product-g\d+\.\w+ 960w/);
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

test('the product page serves product and zoom sizes, small thumbs and the master in the full-screen viewer', async ({ page }) => {
  const card = await firstBuyableCard(page);
  await card.locator('a.catalog-card__media').click();
  await page.waitForLoadState('domcontentloaded');
  const main = page.locator('[data-gallery-main]');
  expect((await main.getAttribute('src')) ?? '').toContain('.product-g');
  expect((await main.getAttribute('srcset')) ?? '').toMatch(/\.product-g\d+\.\w+ 960w, .*\.zoom-g\d+\.\w+ 1600w/);
  const thumbs = page.locator('[data-gallery-thumb]');
  if ((await thumbs.count()) > 1) {
    for (const t of await thumbs.all()) {
      expect((await t.locator('img').getAttribute('src')) ?? '').toContain('.thumb-g');
      expect((await t.getAttribute('data-full')) ?? '').not.toMatch(VARIANT);
    }
  }
  await page.locator('[data-gallery-open]').click();
  const full = page.locator('[data-gallery-dialog-image]');
  await expect(full).toBeVisible();
  expect((await full.getAttribute('src')) ?? '').not.toMatch(VARIANT);
  await expect.poll(() => full.evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth > 0)).toBe(true);
});

test('only the closed set of sizes can be requested', async ({ request, page }) => {
  const card = await firstBuyableCard(page);
  const src = (await card.locator('img').first().getAttribute('src')) ?? '';
  const [, stem] = src.match(/^(\/media\/[\w/-]+)\.card-g\d+\.\w+$/) ?? [];
  expect(stem).toBeTruthy();
  const ext = src.split('.').pop();
  for (const bad of [`${stem}.huge-g1.${ext}`, `${stem}.card-g1.php`, `/media/../etc/passwd.card-g1.${ext}`, `${stem}.card-g9999.${ext}`]) {
    const res = await request.get(bad, { maxRedirects: 0 });
    expect(res.status(), bad).toBe(404);
  }
  const old = await request.get(`${stem}.card-g0.${ext}`, { maxRedirects: 0 });
  expect(old.status()).toBe(302);
  expect(old.headers().location ?? '').toMatch(VARIANT);
  const follow = await request.get(`${stem}.card-g0.${ext}`);
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
  await expect(page.locator('input[name="widths[]"], input[name="include_original"], input[name="jpeg_fallback"]')).toHaveCount(0);
  await expect(page.locator('.media-processing-summary')).toContainText('1920');

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
