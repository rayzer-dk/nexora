import { expect, test, type Page } from '@playwright/test';
import { expectNoHorizontalOverflow, expectNoServerError } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')), page.locator('button[type="submit"]').click()]);
}

// 1x1 PNG is enough for the cover upload; the storefront crops it with object-fit.
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const suffix = Date.now();

test.describe('desktop layouts (1440px)', () => {
  test.use({ viewport: { width: 1440, height: 900 } });

  for (const route of ['/returns', '/shipping', '/about-us', '/privacy-policy', '/faq']) {
    test(`information page ${route} uses content + side panel, not a narrow left column`, async ({ page }) => {
      await page.goto(route, { waitUntil: 'domcontentloaded' });
      await expectNoServerError(page);
      await expectNoHorizontalOverflow(page);
      const body = page.locator('.content-page__body').first();
      const aside = page.locator('.content-page__aside').first();
      await expect(body).toBeVisible();
      await expect(aside).toBeVisible();
      const b = (await body.boundingBox())!;
      const a = (await aside.boundingBox())!;
      expect(b.width, 'text column must be wide').toBeGreaterThan(700);
      expect(a.x, 'side panel sits right of the text').toBeGreaterThan(b.x + b.width - 1);
      expect(a.x + a.width, 'side panel reaches the right edge of the container').toBeGreaterThan(1300);
    });
  }

  test('contacts page shows the form, contact cards and the information text side by side', async ({ page }) => {
    await page.goto('/contact', { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    await expectNoHorizontalOverflow(page);
    const form = (await page.locator('#contact-form').boundingBox())!;
    const side = (await page.locator('.contact-side').boundingBox())!;
    expect(side.x).toBeGreaterThan(form.x + form.width - 1);
  });

  for (const route of ['/blog', '/accessibility', '/withdrawal', '/forum', '/account/login', '/compare', '/downloads']) {
    test(`${route} has no horizontal overflow and no server error`, async ({ page }) => {
      await page.goto(route, { waitUntil: 'domcontentloaded' });
      await expectNoServerError(page);
      await expectNoHorizontalOverflow(page);
    });
  }

  test('blog list shows three cards per row with a fixed image ratio', async ({ page }) => {
    await page.goto('/blog', { waitUntil: 'domcontentloaded' });
    const cards = page.locator('.article-grid .article-card__image');
    expect(await cards.count()).toBeGreaterThan(3);
    const boxes = await cards.evaluateAll((els) => els.slice(0, 4).map((e) => { const r = e.getBoundingClientRect(); return { x: Math.round(r.x), w: Math.round(r.width), h: Math.round(r.height) }; }));
    expect(new Set(boxes.slice(0, 3).map((b) => b.w)).size).toBe(1);
    expect(boxes[0].x).toBeLessThan(boxes[1].x);
    for (const b of boxes) expect(Math.abs(b.w / b.h - 1.6)).toBeLessThan(0.05);
    // every card has either a photo or the placeholder
    await expect(page.locator('.article-card__image:not(.is-placeholder) img').first()).toBeVisible();
  });
});

test.describe('mobile layouts (390px)', () => {
  test.use({ viewport: { width: 390, height: 800 } });
  for (const route of ['/returns', '/contact', '/blog', '/accessibility', '/withdrawal']) {
    test(`${route} fits the phone screen`, async ({ page }) => {
      await page.goto(route, { waitUntil: 'domcontentloaded' });
      await expectNoServerError(page);
      await expectNoHorizontalOverflow(page);
    });
  }
});

test('every demo article has a cover image that loads', async ({ page }) => {
  await page.goto('/blog', { waitUntil: 'networkidle' });
  const slugs = await page.locator('.article-card h2 a, .article-card h3 a').evaluateAll((els) => els.map((e) => e.getAttribute('href') || ''));
  expect(slugs.length).toBeGreaterThan(2);
  const demoCards = page.locator('.article-card:not(:has(a[href*="/blog/e2e-"]))');
  expect(await demoCards.count()).toBeGreaterThan(2);
  expect(await demoCards.locator('.article-card__image.is-placeholder').count(), 'demo articles must not use the placeholder').toBe(0);
});

test('product detail exposes the real photo to the recently viewed list', async ({ page }) => {
  await page.goto('/vivo-s1', { waitUntil: 'domcontentloaded' });
  const image = await page.locator('[data-recent-track]').getAttribute('data-image');
  expect(image).toMatch(/^\/media\//);
  expect(image).not.toContain('product-placeholder');
});

test.describe('admin-managed content', () => {
  test.beforeEach(async ({}, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared CI data once.');
    test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');
  });
  test.use({ viewport: { width: 1440, height: 900 } });

  test('address, map and social links from the admin appear on the contacts page and in JSON-LD', async ({ page }) => {
    await loginAdmin(page);
    await page.goto('/admin/system/storefront-contacts', { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    const form = page.locator('form[action$="/storefront-contacts/save"]');
    const previous = {
      address: await form.locator('input[name="address"]').inputValue(),
      map: await form.locator('input[name="map_input"]').inputValue(),
      instagram: await form.locator('input[name="social_instagram"]').inputValue(),
      telegram: await form.locator('input[name="social_telegram"]').inputValue(),
    };
    await form.locator('input[name="address"]').fill('Kyiv, Khreshchatyk St 1');
    await form.locator('input[name="map_input"]').fill('50.4501, 30.5234');
    await form.locator('input[name="social_instagram"]').fill('https://instagram.com/nexora_e2e');
    await form.locator('input[name="social_telegram"]').fill('https://t.me/nexora_e2e');
    await Promise.all([page.waitForURL(/storefront-contacts/), form.locator('button[type="submit"]').click()]);
    await expectNoServerError(page);
    try {
      await page.goto('/contact', { waitUntil: 'domcontentloaded' });
      await expect(page.locator('.contact-card', { hasText: 'Kyiv, Khreshchatyk St 1' })).toBeVisible();
      await expect(page.locator('iframe.contact-map__frame')).toHaveAttribute('src', /openstreetmap\.org\/export\/embed\.html.*marker=50\.450100%2C30\.523400/);
      await expect(page.locator('.contact-cards a[href="https://instagram.com/nexora_e2e"]')).toBeVisible();
      await expect(page.locator('.contact-cards a[href="https://t.me/nexora_e2e"]')).toBeVisible();
      await expect(page.locator('.contact-cards a[href*="facebook.com"]')).toHaveCount(0);
      await expectNoHorizontalOverflow(page);
      const ld = JSON.parse((await page.locator('script[type="application/ld+json"]').first().textContent()) ?? '{}');
      const org = (ld['@graph'] as Array<Record<string, unknown>>).find((n) => n['@type'] === 'Organization')!;
      expect(org.sameAs).toContain('https://instagram.com/nexora_e2e');
      expect((org.address as Record<string, string>).streetAddress).toContain('Khreshchatyk');
      expect((org.geo as Record<string, number>).latitude).toBeCloseTo(50.4501, 3);
    } finally {
      await page.goto('/admin/system/storefront-contacts', { waitUntil: 'domcontentloaded' });
      const again = page.locator('form[action$="/storefront-contacts/save"]');
      await again.locator('input[name="address"]').fill(previous.address);
      await again.locator('input[name="map_input"]').fill(previous.map);
      await again.locator('input[name="social_instagram"]').fill(previous.instagram);
      await again.locator('input[name="social_telegram"]').fill(previous.telegram);
      await Promise.all([page.waitForURL(/storefront-contacts/), again.locator('button[type="submit"]').click()]);
    }
  });

  test('blog image placement and size are set per article and the lightbox pages through photos', async ({ page }) => {
    const title = `E2E Image Post ${suffix}`;
    const slug = `e2e-image-post-${suffix}`;
    await loginAdmin(page);
    await page.goto('/admin/content/blog/new', { waitUntil: 'domcontentloaded' });
    const form = page.locator('form[data-blog-form]');
    await form.locator('input[name="title"]').fill(title);
    await form.locator('input[name="slug"]').fill(slug);
    await form.locator('textarea[name="excerpt"]').fill('Image placement excerpt.');
    await form.locator('select[name="status"]').selectOption('published');
    await form.locator('input[name="cover"]').setInputFiles({ name: 'cover.png', mimeType: 'image/png', buffer: PNG });
    await form.locator('select[name="image_align"]').selectOption('left');
    await form.locator('select[name="image_size"]').selectOption('s');
    const toggle = page.locator('.rich-editor__source-toggle').first();
    await toggle.click();
    await page.locator('.rich-editor__code .cm-content').first().fill(`<h2>One</h2><p>${'Text. '.repeat(60)}</p><p><img src="/media/demo/dummyjson/134-1.webp" alt="First" width="400" height="400" align="right"></p><p>${'More text. '.repeat(40)}</p><p><img src="/media/demo/dummyjson/134-2.webp" alt="Second" width="400" height="400"></p>`);
    await toggle.click();
    await Promise.all([page.waitForURL(/\/admin\/content\/blog\/\d+\/edit/), form.locator('button[type="submit"]').first().click()]);
    await expectNoServerError(page);
    await expect(page.locator('select[name="image_align"]')).toHaveValue('left');
    await expect(page.locator('select[name="image_size"]')).toHaveValue('s');

    await page.goto(`/blog/${slug}`, { waitUntil: 'networkidle' });
    await expectNoHorizontalOverflow(page);
    const figure = page.locator('.article-figure--left.article-figure--s');
    await expect(figure).toBeVisible();
    expect(await figure.evaluate((el) => getComputedStyle(el).float)).toBe('left');
    expect((await figure.boundingBox())!.width).toBeLessThanOrEqual(240);
    await expect(page.locator('.article-hero')).toHaveCount(0);
    const inline = page.locator('.article-body img[align="right"]');
    expect(await inline.evaluate((el) => getComputedStyle(el).float)).toBe('right');

    // lightbox: cover + two inline photos, previous / next
    await figure.locator('img').click();
    const box = page.locator('.lightbox');
    await expect(box).toBeVisible();
    await expect(page.locator('.lightbox__next')).toBeVisible();
    const first = await page.locator('.lightbox__image').getAttribute('src');
    await page.locator('.lightbox__next').click();
    await expect(page.locator('.lightbox__image')).not.toHaveAttribute('src', first ?? '');
    await page.keyboard.press('ArrowLeft');
    await expect(page.locator('.lightbox__image')).toHaveAttribute('src', first ?? '');
    await page.keyboard.press('Escape');
    await expect(box).toBeHidden();

    // a top placement caps the hero height
    await page.goto('/admin/content/blog', { waitUntil: 'domcontentloaded' });
    await page.locator(`a[href$="/edit"]`, { hasText: title }).first().click();
    await page.locator('select[name="image_align"]').selectOption('none');
    await page.locator('select[name="image_size"]').selectOption('l');
    await Promise.all([page.waitForURL(/\/edit/), page.locator('form[data-blog-form] button[type="submit"]').first().click()]);
    await page.goto(`/blog/${slug}`, { waitUntil: 'networkidle' });
    const hero = page.locator('.article-hero--l');
    await expect(hero).toBeVisible();
    expect((await hero.boundingBox())!.height).toBeLessThanOrEqual(421);
  });
});
