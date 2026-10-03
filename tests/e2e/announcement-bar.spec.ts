import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/|$)/), page.locator('button[type="submit"]').click()]);
}

async function save(page: Page, v: { enabled: boolean; text: string; mode: string; bg?: string; pages: string[] }) {
  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const panel = page.locator('[data-announcement-settings]');
  await panel.locator('input[name="announcement_enabled"]').setChecked(v.enabled);
  await panel.locator('input[name="announcement_text"]').fill(v.text);
  await panel.locator(`input[name="announcement_mode"][value="${v.mode}"]`).check({ force: true });
  if (v.bg) {
    await panel.locator('input[name="announcement_bg"]').fill(v.bg);
    await panel.locator('input[name="announcement_bg_on"]').check();
  } else {
    await panel.locator('input[name="announcement_bg_on"]').uncheck();
  }
  for (const k of ['home', 'catalog', 'category', 'product', 'cart', 'blog', 'content']) {
    await panel.locator(`input[name="announcement_page_${k}"]`).setChecked(v.pages.includes(k));
  }
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/admin/appearance/storefront')),
    page.locator('form[data-dirty-guard] button[type="submit"]').last().click(),
  ]);
}

test('the announcement bar has its own text, colour, mode and pages, and can be switched off', async ({ page, browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings run once.');
  await loginAdmin(page);
  const everywhere = ['home', 'catalog', 'category', 'product', 'cart', 'blog', 'content'];
  const ctx = await browser.newContext({ baseURL: testInfo.project.use.baseURL, locale: 'uk-UA' });
  const shop = await ctx.newPage();
  try {
    await save(page, { enabled: true, text: 'E2E announcement text', mode: 'static', bg: '#7C2D12', pages: ['home', 'catalog'] });
    await shop.goto('/', { waitUntil: 'domcontentloaded' });
    const bar = shop.locator('.announcement-bar');
    await expect(bar).toContainText('E2E announcement text');
    await expect(bar).toHaveAttribute('data-mode', 'static');
    await expect(bar).toHaveCSS('background-color', 'rgb(124, 45, 18)');
    await shop.goto('/catalog', { waitUntil: 'domcontentloaded' });
    await expect(shop.locator('.announcement-bar')).toHaveCount(1);
    await shop.goto('/iphone-x', { waitUntil: 'domcontentloaded' });
    await expect(shop.locator('.announcement-bar')).toHaveCount(0);
    await save(page, { enabled: false, text: 'E2E announcement text', mode: 'static', pages: everywhere });
    await shop.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(shop.locator('.announcement-bar')).toHaveCount(0);
  } finally {
    await save(page, { enabled: true, text: '', mode: 'marquee_mobile', pages: everywhere });
    await ctx.close();
  }
});
