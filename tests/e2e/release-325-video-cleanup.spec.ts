import { expect, test, type Locator, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

// Release 3.25.0: product videos (a link, preview first, player after a click), manual review of unused pictures with a
// restorable trash, files of a picture in the library, and the remembered library folder.

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

/** Saves the product form and waits until the redirect after the POST has loaded (the URL is /edit before and after). */
async function saveProduct(page: Page): Promise<void> {
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && /\/edit$/.test(new URL(r.url()).pathname)),
    page.locator('[data-primary-submit]').first().click(),
  ]);
  await page.waitForLoadState('domcontentloaded');
}

/** Clicks a button whose form asks for confirmation, accepts it and waits until the POST has been answered. */
async function confirmedPost(page: Page, button: Locator, pathname: string): Promise<void> {
  const answered = page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === pathname);
  await button.click();
  await page.locator('[data-confirm-accept]').click({ timeout: 3000 }).catch(() => undefined);
  await answered;
  await page.waitForLoadState('domcontentloaded');
}

test.describe.configure({ mode: 'serial' });

test('a product video is a link: preview first, the player loads only after a click', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared demo data once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await login(page);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  await page.locator('tr').filter({ hasNotText: /Picked|E2E|Warm/i }).locator('a[href*="/admin/catalog/products/"][href$="/edit"]').first().click();
  await page.waitForURL(/\/edit/);
  const editUrl = page.url();
  const slug = await page.locator('#product-form input[name="slug"]').inputValue();

  // a bad link is refused with a message, nothing is saved
  await page.locator('input[name="video_url"]').fill('https://example.com/not-a-video');
  await saveProduct(page);
  await expect(page.locator('.admin-notice.is-error').first()).toBeAttached();

  // a good link with an own preview, placed before the photos
  await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="video_url"]').fill('https://youtu.be/dQw4w9WgXcQ');
  await page.locator('input[name="video_new_title"]').fill('E2E video');
  await page.locator('select[name="video_new_placement"]').selectOption('start');
  await page.locator('input[name="video_poster"]').setInputFiles({ name: 'poster.png', mimeType: 'image/png', buffer: unique() });
  await saveProduct(page);
  await expect(page.locator('[data-product-videos] .admin-video-row')).toHaveCount(1);

  // storefront: the video is the first gallery item, shown as its preview, no player and no request to the video host yet
  const hosts: string[] = [];
  page.on('request', (r) => hosts.push(new URL(r.url()).host));
  await page.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const stage = page.locator('[data-gallery-stage]');
  await expect(stage).toHaveClass(/is-video/);
  await expect(page.locator('[data-gallery-thumb].is-video')).toHaveCount(1);
  await expect(page.locator('.product-gallery__player')).toHaveCount(0);
  await expect(page.locator('[data-gallery-stage] iframe')).toHaveCount(0);
  expect(hosts.some((h) => /youtube|ytimg|vimeo/.test(h))).toBe(false);
  await expect.poll(() => page.locator('[data-gallery-main]').evaluate((el: HTMLImageElement) => el.decode().then(() => true, () => false))).toBe(true);

  // a click loads the player (privacy-friendly host, autoplay)
  await page.locator('[data-gallery-open]').click();
  const frame = page.locator('[data-gallery-stage] iframe');
  await expect(frame).toHaveCount(1);
  expect(await frame.getAttribute('src')).toMatch(/^https:\/\/www\.youtube-nocookie\.com\/embed\/dQw4w9WgXcQ\?.*autoplay=1/);
  await expect(stage).toHaveClass(/is-playing/);

  // moving to a photo stops the video and hides the player
  const thumbs = page.locator('[data-gallery-thumb]');
  if ((await thumbs.count()) > 1) {
    await thumbs.nth(1).click();
    await expect(frame).toHaveCount(0);
    await expect(stage).not.toHaveClass(/is-video/);
  }

  // after the photos
  await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
  await page.locator('select[name^="video_placement["]').selectOption('end');
  await saveProduct(page);
  // the storefront keeps product data for a few seconds, so look again until the change shows
  await expect(async () => {
    await page.goto(`/${slug}?fresh=${Date.now()}`, { waitUntil: 'domcontentloaded' });
    const classes = await page.locator('[data-gallery-thumb]').evaluateAll((els) => els.map((e) => e.className));
    expect(classes.length).toBeGreaterThan(1);
    expect(classes.at(-1)).toMatch(/is-video/);
    expect(classes[0]).not.toMatch(/is-video/);
  }).toPass({ timeout: 25000, intervals: [1000] });

  // remove it
  await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
  await page.locator('[data-product-videos] button.is-danger').first().click();
  const accept = page.locator('[data-confirm-accept]');
  if (await accept.isVisible().catch(() => false)) await accept.click();
  await page.waitForURL(/\/edit/);
  await expect(page.locator('[data-product-videos] .admin-video-row')).toHaveCount(0);
  await expect(async () => {
    await page.goto(`/${slug}?fresh=${Date.now()}`, { waitUntil: 'domcontentloaded' });
    expect(await page.locator('[data-gallery-thumb].is-video').count()).toBe(0);
  }).toPass({ timeout: 25000, intervals: [1000] });
});

test('unused pictures are reviewed by hand: deselect, move to the trash, restore', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared data once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await login(page);
  await page.goto('/admin/media', { waitUntil: 'domcontentloaded' });
  // choosing a file submits the upload form by itself
  await Promise.all([
    page.waitForEvent('load'), // the page reloads itself after the upload
    page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/media/upload'),
    page.locator('[data-media-drop] input[type="file"]').setInputFiles({ name: 'unused.png', mimeType: 'image/png', buffer: unique() }),
  ]);
  await page.waitForLoadState('domcontentloaded');

  await page.goto('/admin/media/cleanup?days=0', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const tiles = page.locator('.media-cleanup__tile');
  const total = await tiles.count();
  expect(total).toBeGreaterThanOrEqual(1);
  const counter = page.locator('[data-cleanup-count]');
  await expect(counter).toContainText(String(total));

  // everything is selected at first; a click deselects one, "deselect all" clears, "select all" restores
  await tiles.first().click();
  await expect(tiles.first()).toHaveClass(/is-off/);
  await page.locator('[data-cleanup-none]').click();
  await expect(page.locator('[data-cleanup-submit]')).toBeDisabled();
  await page.locator('[data-cleanup-all]').click();
  await expect(page.locator('.media-cleanup__tile.is-off')).toHaveCount(0);

  // only the picture uploaded just now (the newest) goes to the trash
  await page.locator('[data-cleanup-none]').click();
  const mine = await tiles.last().locator('input').getAttribute('value');
  expect(mine).toMatch(/^\d+$/);
  await tiles.last().click();
  await confirmedPost(page, page.locator('[data-cleanup-submit]'), '/admin/media/cleanup/trash');
  await expect(page.locator('.admin-notice').first()).toBeAttached();
  await page.goto('/admin/media/cleanup?days=0', { waitUntil: 'domcontentloaded' });
  await expect(page.locator(`.media-cleanup__tile input[value="${mine}"]`)).toHaveCount(0);
  const trashRows = page.locator('.media-trash-list li');
  expect(await trashRows.count()).toBeGreaterThanOrEqual(1);

  // restore it
  await page.locator(`.media-trash-list input[value$=":${mine}"]`).check();
  await confirmedPost(page, page.locator('.media-cleanup-trash button[type="submit"]'), '/admin/media/cleanup/restore');
  await page.goto('/admin/media/cleanup?days=0', { waitUntil: 'domcontentloaded' });
  await expect(page.locator(`.media-cleanup__tile input[value="${mine}"]`)).toHaveCount(1);
});

test('the media library shows the files of a picture and remembers the last folder', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Uses the shared admin session once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await login(page);
  await page.goto('/admin/media?folder=none', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await page.goto('/admin/media', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.media-folder.is-active')).toHaveAttribute('href', /folder=none/);
  await page.goto('/admin/media?folder=all', { waitUntil: 'domcontentloaded' });
  await page.goto('/admin/media', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.media-folder.is-active')).toHaveAttribute('href', /folder=all/);

  const card = page.locator('.media-card').filter({ has: page.locator('details[data-media-files]') }).first();
  await card.locator('button[data-media-edit]').first().click();
  await card.locator('details[data-media-files] summary').click();
  await expect(card.locator('.media-files__table tr').first()).toBeVisible();
  expect(await card.locator('.media-files__table tr').count()).toBeGreaterThanOrEqual(2);
  await page.goto('/admin/media?kind=video', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
});
