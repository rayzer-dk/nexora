import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

async function save(page: Page, opts: { search: boolean; share: boolean; related: boolean; layout: string }) {
  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const panel = page.locator('[data-blog-settings]');
  await panel.locator('input[name="blog_index_show_search"]').setChecked(opts.search);
  await panel.locator('input[name="blog_article_show_share"]').setChecked(opts.share);
  await panel.locator('input[name="blog_article_show_related"]').setChecked(opts.related);
  await panel.locator(`input[name="blog_index_layout"][value="${opts.layout}"]`).check({ force: true });
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/admin/appearance/storefront')),
    page.locator('form[data-dirty-guard] button[type="submit"]').last().click(),
  ]);
}

test('blog blocks and list layout follow Appearance → Blog', async ({ page, browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings run once.');
  await loginAdmin(page);
  const ctx = await browser.newContext({ baseURL: testInfo.project.use.baseURL, locale: 'uk-UA' });
  const shop = await ctx.newPage();
  try {
    await save(page, { search: false, share: false, related: false, layout: 'list' });
    await shop.goto('/blog', { waitUntil: 'domcontentloaded' });
    await expect(shop.locator('.blog-search')).toHaveCount(0);
    await expect(shop.locator('.article-grid').first()).toHaveAttribute('data-layout', 'list');
    await shop.goto('/blog/smart-home-basics', { waitUntil: 'domcontentloaded' });
    await expectNoServerError(shop);
    await expect(shop.locator('.article-share')).toHaveCount(0);
    await expect(shop.locator('#article-related-title')).toHaveCount(0);
  } finally {
    await save(page, { search: true, share: true, related: true, layout: 'grid' });
  }
  await shop.goto('/blog', { waitUntil: 'domcontentloaded' });
  await expect(shop.locator('.blog-search')).toHaveCount(1);
  await shop.goto('/blog/smart-home-basics', { waitUntil: 'domcontentloaded' });
  await expect(shop.locator('.article-share')).toHaveCount(1);
  await ctx.close();
});
