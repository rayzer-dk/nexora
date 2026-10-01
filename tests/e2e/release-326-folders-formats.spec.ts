import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError, openProductTab } from './helpers';

// Release 3.26.0: originals live in real library folders, sizes and formats are a separate cache, the format is a choice
// (WebP only / AVIF with a WebP fallback), photos and videos of a product are one list ordered by dragging.

const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const unique = () => Buffer.concat([PNG, Buffer.from(`-${Date.now()}-${Math.random()}`)]);

async function login(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')),
    page.locator('button[type="submit"]').click(),
  ]);
}

async function saveProduct(page: Page): Promise<void> {
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && /\/edit$/.test(new URL(r.url()).pathname)),
    page.locator('[data-primary-submit]').first().click(),
  ]);
  await page.waitForLoadState('domcontentloaded');
}

test.describe.configure({ mode: 'serial' });

const stamp = String(Date.now()).slice(-6);
const top = `E2E Phones ${stamp}`;
const topSlug = `e2e-phones-${stamp}`;

test('a picture is stored as an original in the real folder chosen at upload; sizes live in the cache', async ({ page, request }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared data once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await login(page);
  await page.goto('/admin/media?folder=all', { waitUntil: 'domcontentloaded' });

  // nested folders: a top-level one, then one inside it
  await page.locator('.media-folder-form input[name="name"]').fill(top);
  await Promise.all([page.waitForURL(/\/admin\/media/), page.locator('.media-folder-form button[type="submit"]').click()]);
  await page.locator('.media-folder-form input[name="name"]').fill(`Apple ${stamp}`);
  await page.locator('.media-folder-form select[name="parent_id"]').selectOption({ label: top });
  await Promise.all([page.waitForURL(/\/admin\/media/), page.locator('.media-folder-form button[type="submit"]').click()]);
  const apple = page.locator('.media-folder-row--depth-1 .media-folder', { hasText: `Apple ${stamp}` }).first();
  await expect(apple).toBeVisible();
  await apple.click();
  await page.waitForLoadState('domcontentloaded');

  // upload into the open folder
  await Promise.all([
    page.waitForEvent('load'),
    page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/media/upload'),
    page.locator('[data-media-drop] input[type="file"]').setInputFiles({ name: 'Test Photo 326.png', mimeType: 'image/png', buffer: unique() }),
  ]);
  await expectNoServerError(page);
  const original = `/media/${topSlug}/apple-${stamp}/test-photo-326.png`;
  const res = await request.get(original);
  expect(res.status(), 'the original sits in the folder path with a readable name').toBe(200);
  expect(res.headers()['content-type']).toMatch(/image\/png/);

  // the same name again gets -2 (a different picture); the page script must be ready before a file is chosen
  await page.waitForLoadState('networkidle');
  await Promise.all([
    page.waitForEvent('load'),
    page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/media/upload'),
    page.locator('[data-media-drop] input[type="file"]').setInputFiles({ name: 'Test Photo 326.png', mimeType: 'image/png', buffer: unique() }),
  ]);
  expect((await request.get(`/media/${topSlug}/apple-${stamp}/test-photo-326-2.png`)).status()).toBe(200);

  // sizes are a cache: the library card uses a cache URL that is created on first request
  const thumb = page.locator('.media-card img').first();
  const src = (await thumb.getAttribute('src')) ?? '';
  expect(src).toMatch(/^\/media\/cache\/card-g\d+\/e2e-phones-\d+\/apple-\d+\/test-photo-326(-2)?\.webp$/);
  const made = await request.get(src);
  expect(made.status()).toBe(200);
  expect(made.headers()['content-type']).toMatch(/image\/webp/);
  // the files panel lists the original and the made size
  const card = page.locator('.media-card').filter({ has: page.locator('details[data-media-files]') }).first();
  await card.locator('button[data-media-edit]').first().click();
  await card.locator('details[data-media-files] summary').click();
  await expect(card.locator('.media-files__table tr').first()).toBeVisible();
});

test('the format is a choice: WebP only or AVIF with a WebP fallback', async ({ page, request }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared settings once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await login(page);
  const choose = async (preset: string) => {
    await page.goto('/admin/media', { waitUntil: 'domcontentloaded' });
    await page.locator(`input[name="preset"][value="${preset}"]`).check({ force: true });
    await Promise.all([page.waitForURL(/\/admin\/media/), page.locator('.media-processing-form button[type="submit"]').click()]);
    await expectNoServerError(page);
  };
  await choose('modern');
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const picture = page.locator('[data-product-card] picture').first();
  await expect(picture).toHaveCount(1);
  const source = picture.locator('source[type="image/avif"]');
  const avifSet = (await source.getAttribute('srcset')) ?? '';
  expect(avifSet).toMatch(/cache\/card-g\d+\/.*\.avif 480w/);
  const avifUrl = avifSet.split(',').map((s) => s.trim().split(' ')[0]).find((u) => u.includes('/card-g')) ?? '';
  const avif = await request.get(avifUrl);
  expect(avif.status()).toBe(200);
  expect(avif.headers()['content-type']).toMatch(/image\/avif/);
  const fallback = (await picture.locator('img').getAttribute('src')) ?? '';
  expect(fallback).toMatch(/\.webp$/);

  await choose('recommended');
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-product-card] picture')).toHaveCount(0);
  await expect(page.locator('[data-product-card] img[srcset]').first()).toHaveAttribute('srcset', /\.webp/);
});

test('product photos are ordered by dragging, new photos go to the chosen folder', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared demo data once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await login(page);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  await page.locator('tr').filter({ hasNotText: /Picked|E2E|Warm/i }).locator('a[href*="/admin/catalog/products/"][href$="/edit"]').nth(3).click();
  await page.waitForURL(/\/edit/);
  await openProductTab(page, 'media');
  const editUrl = page.url();

  const select = page.locator('select[name="upload_folder"]');
  await expect(select).toHaveCount(1);
  await select.selectOption({ label: `${top} / Apple ${stamp}` });
  await page.locator('input[name="images[]"]').setInputFiles([
    { name: 'drag-one.png', mimeType: 'image/png', buffer: unique() },
    { name: 'drag-two.png', mimeType: 'image/png', buffer: unique() },
  ]);
  await saveProduct(page);
  await expectNoServerError(page);
  await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
  await openProductTab(page, 'media');
  const items = page.locator('[data-media-sortable] > [data-media-token][data-media-kind="photo"]');
  const count = await items.count();
  expect(count).toBeGreaterThanOrEqual(2);
  // the choice of folder is remembered for the next product
  await expect(page.locator('select[name="upload_folder"]')).toHaveValue(/\d+/);

  const lastToken = (await items.nth(count - 1).getAttribute('data-media-token')) ?? '';
  const saved = page.waitForResponse((r) => r.request().method() === 'POST' && /\/media\/order$/.test(new URL(r.url()).pathname));
  await items.nth(count - 1).dragTo(items.nth(0));
  expect((await saved).status()).toBe(200);
  await expect(page.locator('[data-media-sortable] > [data-media-token]').first()).toHaveAttribute('data-media-token', lastToken);
  await expect(page.locator('[data-media-sortable] > .is-primary')).toHaveCount(1);

  // the order survives a reload; the first photo is the main one
  await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
  await openProductTab(page, 'media');
  await expect(page.locator('[data-media-sortable] > [data-media-token]').first()).toHaveAttribute('data-media-token', lastToken);
  await expect(page.locator('[data-media-sortable] > .is-primary')).toHaveAttribute('data-media-token', lastToken);

  // arrow buttons do the same without a mouse (touch screens)
  const second = page.locator('[data-media-sortable] > [data-media-token]').nth(1);
  const secondToken = (await second.getAttribute('data-media-token')) ?? '';
  const moved = page.waitForResponse((r) => r.request().method() === 'POST' && /\/media\/order$/.test(new URL(r.url()).pathname));
  await second.locator('[data-media-move="-1"]').click();
  expect((await moved).status()).toBe(200);
  await expect(page.locator('[data-media-sortable] > [data-media-token]').first()).toHaveAttribute('data-media-token', secondToken);
});
