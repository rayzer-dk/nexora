import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

// Product page (gallery + viewer + tabs + buy box), product card and catalog filters (3.23.0).

async function firstProductWithGallery(page: Page): Promise<string> {
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const hrefs = await page.locator('[data-product-card] h2 a').evaluateAll((els) => els.map((el) => el.getAttribute('href') || ''));
  for (const href of ['/iphone-x', ...hrefs]) {
    await page.goto(href, { waitUntil: 'domcontentloaded' });
    if ((await page.locator('[data-gallery-thumb]').count()) > 1) return href;
  }
  throw new Error('no product with several photos in the demo data');
}

test('product page: compact buy box, tabs with anchors, share popover', async ({ page }) => {
  const href = await firstProductWithGallery(page);
  await expectNoServerError(page);
  await expect(page.locator('.product-page h1')).toBeVisible();
  await expect(page.locator('.product-price__current')).toBeVisible();
  const buttons = page.locator('.buy-actions__buttons > .button');
  await expect(buttons.first()).toBeVisible();
  expect(await buttons.count()).toBeGreaterThanOrEqual(1);
  // Add to cart and Buy now share one row.
  if ((await buttons.count()) === 2) {
    const a = await buttons.nth(0).boundingBox();
    const b = await buttons.nth(1).boundingBox();
    expect(Math.abs((a?.y ?? 0) - (b?.y ?? 1))).toBeLessThan(2);
  }

  const tabs = page.locator('[data-product-tabs] [data-tab]');
  expect(await tabs.count()).toBeGreaterThanOrEqual(2);
  await expect(page.locator('[data-product-tabs]')).toHaveClass(/is-enhanced/);
  const second = tabs.nth(1);
  const id = await second.getAttribute('data-tab');
  await second.click();
  await expect(page.locator(`[data-tab-panel="${id}"]`)).toBeVisible();
  await expect(page.locator(`[data-tab-panel]:not([data-tab-panel="${id}"])`).first()).toBeHidden();
  expect(page.url()).toContain(`#tab-${id}`);

  // A deep link opens the right tab.
  await page.goto(`${href}#tab-${id}`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator(`[data-tab-panel="${id}"]`)).toBeVisible();

  const share = page.locator('.product-page [data-share-pop]').first();
  await share.locator('summary').click();
  await expect(share.locator('a[href^="https://t.me/share/url?url="]')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(share.locator('a[href^="https://t.me/share/url?url="]')).toBeHidden();
});

test('full-screen photo viewer browses pictures: arrows, keyboard, thumbnails, counter, zoom', async ({ page }) => {
  await firstProductWithGallery(page);
  const total = await page.locator('[data-gallery-thumb]').count();
  await page.locator('[data-gallery-open]').click();
  const dialog = page.locator('[data-gallery-dialog]');
  await expect(dialog).toBeVisible();
  const counter = dialog.locator('[data-lightbox-counter]');
  await expect(counter).toHaveText(`1 / ${total}`);
  const image = dialog.locator('[data-gallery-dialog-image]');
  const first = await image.getAttribute('src');

  await dialog.locator('[data-lightbox-next]').click();
  await expect(counter).toHaveText(`2 / ${total}`);
  expect(await image.getAttribute('src')).not.toBe(first);
  await page.keyboard.press('ArrowRight');
  await expect(counter).toHaveText(`${Math.min(3, total)} / ${total}`);
  await page.keyboard.press('ArrowLeft');
  await expect(counter).toHaveText(`2 / ${total}`);
  await dialog.locator('[data-lightbox-thumbs] button').first().click();
  await expect(counter).toHaveText(`1 / ${total}`);
  await dialog.locator('[data-lightbox-prev]').click();
  await expect(counter).toHaveText(`${total} / ${total}`);

  await dialog.locator('[data-lightbox-zoom-in]').click();
  await expect(dialog.locator('[data-lightbox-stage]')).toHaveClass(/is-zoomed/);
  await dialog.locator('[data-lightbox-zoom-out]').click();
  await dialog.locator('[data-lightbox-zoom-out]').click();
  await expect(dialog.locator('[data-lightbox-stage]')).not.toHaveClass(/is-zoomed/);

  // The page gallery follows the viewer, and Escape closes it.
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(page.locator('[data-gallery-counter]')).toHaveText(`${total} / ${total}`);
});

test('main gallery: arrows and keyboard change the picture', async ({ page }) => {
  await firstProductWithGallery(page);
  const total = await page.locator('[data-gallery-thumb]').count();
  const counter = page.locator('[data-gallery-counter]');
  await page.locator('[data-gallery-next]').click();
  await expect(counter).toHaveText(`2 / ${total}`);
  await page.locator('[data-gallery-stage]').focus();
  await page.keyboard.press('ArrowRight');
  await expect(counter).toHaveText(`${Math.min(3, total)} / ${total}`);
});

test('product card: fixed-ratio image, badges, tools, price and add-to-cart', async ({ page }) => {
  await page.goto('/smartphones', { waitUntil: 'domcontentloaded' });
  const card = page.locator('[data-product-card]').first();
  await expect(card.locator('.catalog-card__media')).toBeVisible();
  const box = await card.locator('.catalog-card__media').boundingBox();
  expect(Math.abs((box?.width ?? 1) - (box?.height ?? 2))).toBeLessThan(2);
  await expect(card.locator('.catalog-card__price strong')).toBeVisible();
  await expect(card.locator('.catalog-card__compare button')).toBeVisible();
  // all cards in a row share one height
  const heights = await page.locator('[data-product-card]').evaluateAll((els) => els.slice(0, 4).map((el) => Math.round(el.getBoundingClientRect().height)));
  expect(new Set(heights).size).toBe(1);
});

test('catalog filters: sidebar, instant apply, active chips, sort and page size keep shareable URLs', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name.includes('mobile'), 'desktop layout');
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/smartphones', { waitUntil: 'networkidle' });
  const panel = page.locator('[data-filters-panel]');
  await expect(panel).toBeVisible();
  await expect(page.locator('.cf-toolbar__filters')).toBeHidden();
  await expect(panel.locator('details.cf__group').first()).toHaveAttribute('open', '');

  // instant apply: ticking "in stock" reloads with a clean URL and shows a chip
  await panel.locator('input[name="in_stock"]').check();
  await page.waitForURL(/in_stock=1/);
  await page.waitForLoadState('domcontentloaded');
  expect(page.url()).not.toContain('q=');
  expect(page.url()).not.toContain('min_price');
  const chip = page.locator('.cf-chip');
  await expect(chip).toHaveCount(1);
  await expect(page.locator('.cf-toolbar__count')).toContainText(/\d/);

  // sort + page size are plain query parameters
  await page.locator('select[name="sort"]').selectOption('price_asc');
  await page.waitForURL(/sort=price_asc/);
  await page.locator('select[name="per_page"]').selectOption('12');
  await page.waitForURL(/per_page=12/);
  expect(page.url()).toContain('in_stock=1');

  // a rating filter narrows results and is removable through its chip
  await page.goto('/smartphones?rating=4', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.cf-chip')).toContainText('4+');
  await page.locator('.cf-chip').first().click();
  await page.waitForURL((url) => !url.search.includes('rating'));

  // clear all
  await page.goto('/smartphones?in_stock=1&min_price=100&sort=price_desc', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.cf-chip')).toHaveCount(2);
  await page.locator('.cf-chips__clear').click();
  await page.waitForURL((url) => url.search === '');
});

test('catalog filters on mobile: bottom sheet with an explicit Apply button', async ({ browser }) => {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 800 } });
  const page = await ctx.newPage();
  await page.goto('/smartphones', { waitUntil: 'networkidle' });
  const panel = page.locator('[data-filters-panel]');
  await expect(panel).toBeHidden();
  await page.locator('[data-filters-open]').click();
  await expect(panel).toBeVisible();
  await panel.locator('input[name="in_stock"]').check();
  // no instant reload inside the sheet
  await page.waitForTimeout(700);
  expect(page.url()).not.toContain('in_stock');
  await panel.locator('.cf__apply').click();
  await page.waitForURL(/in_stock=1/);
  await expect(page.locator('.cf-chip')).toHaveCount(1);
  await ctx.close();
});

test('catalog backend: rating and per_page parameters are honoured', async ({ request }) => {
  const all = await request.get('/catalog');
  expect(all.status()).toBe(200);
  const small = await request.get('/catalog?per_page=12');
  expect(small.status()).toBe(200);
  const cards = (html: string) => (html.match(/data-product-card/g) ?? []).length;
  expect(cards(await small.text())).toBeLessThanOrEqual(12);
  const rated = await request.get('/catalog?rating=5');
  expect(rated.status()).toBe(200);
  expect(cards(await rated.text())).toBeLessThanOrEqual(cards(await all.text()));
  // a nonsense value never breaks the page
  expect((await request.get('/catalog?rating=abc&per_page=9999')).status()).toBe(200);
});

test('admin product form edits the SEO title/description and the old price shown on the storefront', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared demo data once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')),
    page.locator('button[type="submit"]').click(),
  ]);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  // Other specs create draft products that sort first; this one needs a published demo product.
  const edit = page.locator('tr').filter({ hasNotText: /Picked|E2E|Warm/i }).locator('a[href*="/admin/catalog/products/"][href$="/edit"]').first();
  await edit.click();
  await page.waitForURL(/\/edit/);
  const form = page.locator('#product-form');
  const oldMeta = await form.locator('input[name="meta_title"]').inputValue();
  const oldCompare = await form.locator('input[name="compare_at_price"]').inputValue();
  const price = Number((await form.locator('input[name="price"]').inputValue()).replace(/[^\d,.]/g, '').replace(',', '.'));
  const url = page.url();
  const productUrl = await page.evaluate(() => document.querySelector<HTMLAnchorElement>('a[href^="/"][target="_blank"], a[data-view-on-store]')?.getAttribute('href') || '');

  const title = `E2E SEO title ${Date.now()}`;
  await form.locator('input[name="meta_title"]').fill(title);
  await form.locator('textarea[name="meta_description"]').fill('E2E SEO description');
  await form.locator('input[name="compare_at_price"]').fill(String((price * 2).toFixed(2)));
  await page.locator('[data-primary-submit]').first().click();
  await page.waitForLoadState('networkidle');
  await expect(page.locator('#product-form input[name="meta_title"]')).toHaveValue(title);
  await expect(page.locator('#product-form input[name="compare_at_price"]')).not.toHaveValue('');

  if (productUrl) {
    const html = await (await page.request.get(productUrl)).text();
    expect(html).toContain(title);
    expect(html).toContain('product-price__discount');
  }

  // restore
  await page.locator('#product-form input[name="meta_title"]').fill(oldMeta);
  await page.locator('#product-form textarea[name="meta_description"]').fill('');
  await page.locator('#product-form input[name="compare_at_price"]').fill(oldCompare);
  await page.locator('[data-primary-submit]').first().click();
  await page.waitForLoadState('networkidle');
});
