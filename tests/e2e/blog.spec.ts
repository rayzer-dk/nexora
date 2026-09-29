import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')), page.locator('button[type="submit"]').click()]);
}

test.beforeEach(async ({}, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared CI data once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');
});

const suffix = Date.now();
const categoryName = `E2E Guides ${suffix}`;
const categorySlug = `e2e-guides-${suffix}`;
const title = `E2E Blog Post ${suffix}`;
const slug = `e2e-blog-post-${suffix}`;
const body = '<h2>First section</h2><p>' + 'Alpha beta gamma delta. '.repeat(30) + '</p><h2>Second section</h2><p>Second body.</p><h3>Details</h3><p>Third body.</p>';

async function sitemapText(page: Page): Promise<string> {
  const index = await (await page.request.get('/sitemap.xml')).text();
  const locs = [...index.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1]);
  let all = '';
  for (const loc of locs) all += await (await page.request.get(new URL(loc).pathname)).text();
  return all;
}

async function fillArticle(page: Page, opts: { title: string; slug: string; status: 'draft' | 'published'; publishAt?: string; category?: string; tags?: string; withBody?: boolean }): Promise<void> {
  await page.goto('/admin/content/blog/new', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const form = page.locator('form[data-blog-form]');
  await form.locator('input[name="title"]').fill(opts.title);
  await form.locator('input[name="slug"]').fill(opts.slug);
  await form.locator('textarea[name="excerpt"]').fill('E2E excerpt for the blog post.');
  await form.locator('input[name="author_name"]').fill('E2E Author');
  if (opts.tags) await form.locator('input[name="tags"]').fill(opts.tags);
  if (opts.category) await form.locator('select[name="category_id"]').selectOption({ label: opts.category });
  await form.locator('input[name="meta_title"]').fill(`${opts.title} | SEO`);
  await form.locator('textarea[name="meta_description"]').fill('E2E meta description that is long enough to be a decent snippet for search engines.');
  await form.locator('select[name="status"]').selectOption(opts.status);
  if (opts.publishAt) await form.locator('input[name="published_at"]').fill(opts.publishAt);
  const toggle = page.locator('.rich-editor__source-toggle').first();
  await toggle.click();
  await page.locator('.rich-editor__source').first().fill(body);
  await toggle.click();
  await expect(page.locator('textarea[name="body_html"]')).toHaveValue(/First section/);
  await Promise.all([page.waitForURL(/\/admin\/content\/blog\/\d+\/edit/), form.locator('button[type="submit"]').first().click()]);
  await expectNoServerError(page);
}

test('admin creates a category and a published article that the storefront renders with full SEO', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/content/blog/categories', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const catForm = page.locator('form[data-blog-category-form]');
  await catForm.locator('input[name="name"]').fill(categoryName);
  await catForm.locator('input[name="slug"]').fill(categorySlug);
  await catForm.locator('textarea[name="description"]').fill('E2E category description.');
  await Promise.all([page.waitForLoadState('domcontentloaded'), catForm.locator('button[type="submit"]').click()]);
  await expect(page.locator(`[data-blog-category="${categorySlug}"]`)).toBeVisible();

  await fillArticle(page, { title, slug, status: 'published', category: categoryName, tags: 'e2e seo, guides' });
  await expect(page.locator('.admin-notice.is-success')).toHaveCount(1);
  await expect(page.locator('[data-serp-title]')).toContainText('SEO');

  // Public article page.
  const response = await page.goto(`/blog/${slug}`, { waitUntil: 'domcontentloaded' });
  expect(response?.status()).toBe(200);
  await expect(page.locator('h1')).toHaveText(title);
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', new RegExp(`/blog/${slug}$`));
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /index,follow/);
  await expect(page.locator('meta[property="og:type"]')).toHaveAttribute('content', 'article');
  await expect(page.locator('meta[property="og:url"]')).toHaveCount(1);
  await expect(page.locator('.article-toc a')).toHaveCount(3);
  await expect(page.locator('.article-body h2#first-section')).toBeVisible();
  await expect(page.locator('.article-tags a')).toHaveCount(2);
  await expect(page.locator('.article-header__meta')).toContainText('E2E Author');
  await expect(page.locator('link[rel="alternate"][type="application/rss+xml"]')).toHaveCount(1);
  const ld = JSON.parse((await page.locator('script[type="application/ld+json"]').first().innerText()));
  const nodes: Array<Record<string, unknown>> = ld['@graph'] ?? [ld];
  const post = nodes.find((n) => n['@type'] === 'BlogPosting') as Record<string, unknown>;
  expect(post).toBeTruthy();
  expect(post.headline).toBe(title);
  expect((post.author as Record<string, string>).name).toBe('E2E Author');
  expect(String(post.datePublished)).toMatch(/^\d{4}-\d{2}-\d{2}T/);
  expect(String(post.keywords)).toContain('e2e seo');
  expect(nodes.some((n) => n['@type'] === 'BreadcrumbList')).toBe(true);
  await expect(page.locator('img[src=""]')).toHaveCount(0);

  // Listing, category, tag, search, feed, sitemap.
  await page.goto('/blog', { waitUntil: 'domcontentloaded' });
  await expect(page.locator(`a[href="/blog/${slug}"]`).first()).toBeVisible();
  await page.goto(`/blog/category/${categorySlug}`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('h1')).toHaveText(categoryName);
  await expect(page.locator(`a[href="/blog/${slug}"]`).first()).toBeVisible();
  await page.goto('/blog/tag/e2e-seo', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
  await expect(page.locator(`a[href="/blog/${slug}"]`).first()).toBeVisible();
  await page.goto(`/blog?q=${encodeURIComponent(`Blog Post ${suffix}`)}`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator(`a[href="/blog/${slug}"]`).first()).toBeVisible();
  const feed = await page.request.get('/blog/feed.xml');
  expect(feed.status()).toBe(200);
  expect(feed.headers()['content-type']).toContain('rss+xml');
  expect(await feed.text()).toContain(title);
  const map = await sitemapText(page);
  expect(map).toContain(`/blog/${slug}`);
  expect(map).toContain(`/blog/category/${categorySlug}`);
});

test('drafts and scheduled articles are hidden everywhere until published', async ({ page }) => {
  await loginAdmin(page);
  const draftSlug = `e2e-draft-${suffix}`;
  const futureSlug = `e2e-future-${suffix}`;
  await fillArticle(page, { title: `E2E Draft ${suffix}`, slug: draftSlug, status: 'draft' });
  await fillArticle(page, { title: `E2E Future ${suffix}`, slug: futureSlug, status: 'published', publishAt: '2099-01-01T10:00' });
  for (const hidden of [draftSlug, futureSlug]) {
    const res = await page.goto(`/blog/${hidden}`, { waitUntil: 'domcontentloaded' });
    expect(res?.status(), `${hidden} must be 404`).toBe(404);
  }
  await page.goto('/blog', { waitUntil: 'domcontentloaded' });
  await expect(page.locator(`a[href="/blog/${draftSlug}"]`)).toHaveCount(0);
  await expect(page.locator(`a[href="/blog/${futureSlug}"]`)).toHaveCount(0);
  expect(await (await page.request.get('/blog/feed.xml')).text()).not.toContain(`E2E Future ${suffix}`);
  const map = await sitemapText(page);
  expect(map).not.toContain(draftSlug);
  expect(map).not.toContain(futureSlug);
  await page.goto('/admin/content/blog?status=scheduled', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-blog-table] .admin-badge.is-scheduled').first()).toBeVisible();
});

test('changing the slug keeps a redirect; deleting needs confirmation and removes the article', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/content/blog?q=' + encodeURIComponent(title), { waitUntil: 'domcontentloaded' });
  await page.locator('[data-blog-row] a[href$="/edit"]').first().click();
  const newSlug = `${slug}-renamed`;
  await page.locator('input[name="slug"]').fill(newSlug);
  await Promise.all([page.waitForURL(/\/edit/), page.locator('form[data-blog-form] button[type="submit"]').first().click()]);
  await expectNoServerError(page);
  const moved = await page.request.get(`/blog/${slug}`, { maxRedirects: 0 });
  expect(moved.status()).toBe(301);
  expect(moved.headers()['location']).toContain(newSlug);
  expect((await page.goto(`/blog/${newSlug}`, { waitUntil: 'domcontentloaded' }))?.status()).toBe(200);

  await page.goto('/admin/content/blog?q=' + encodeURIComponent(title), { waitUntil: 'domcontentloaded' });
  await page.locator('[data-blog-row] form[data-confirm] button[type="submit"]').first().click();
  await expect(page.locator('[data-admin-confirm]')).toBeVisible();
  await page.locator('[data-admin-confirm] [data-confirm-accept]').click();
  await expect(page.locator('.admin-notice.is-success')).toHaveCount(1);
  expect((await page.goto(`/blog/${newSlug}`, { waitUntil: 'domcontentloaded' }))?.status()).toBe(404);

  await page.goto('/admin/content/blog/categories', { waitUntil: 'domcontentloaded' });
  await page.locator(`[data-blog-category="${categorySlug}"] form[data-confirm] button[type="submit"]`).click();
  await page.locator('[data-admin-confirm] [data-confirm-accept]').click();
  await expect(page.locator(`[data-blog-category="${categorySlug}"]`)).toHaveCount(0);
});
