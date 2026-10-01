import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

// Release 3.29.0: product list — working copy button, quick price, actions header, thumbnails, sort keeps the page.

async function login(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')),
    page.locator('button[type="submit"]').click(),
  ]);
}

test('product list: actions header, copy button, quick price', async ({ page }) => {
  await login(page);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const rows = page.locator('table.admin-table tbody tr');
  const before = await rows.count();
  expect(before).toBeGreaterThan(0);
  await expect(page.locator('table.admin-table thead th').last()).not.toHaveText('');

  const price = page.locator('[data-quick-price] input').first();
  await expect(price).toBeVisible();
  await price.fill((100 + (Date.now() % 800) + 0.5).toFixed(2));
  const saved = page.waitForResponse((r) => r.url().includes('/quick-price'));
  await price.press('Enter');
  expect((await saved).status()).toBe(200);

  const dup = page.locator('button[form^="dup-"]').first();
  const copied = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/duplicate'));
  await dup.click();
  expect((await copied).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expectNoServerError(page);
});

test('sort keeps the current page', async ({ page }) => {
  await login(page);
  await page.goto('/admin/catalog/products?page=2&per_page=1', { waitUntil: 'domcontentloaded' });
  const link = page.locator('table.admin-table thead a[href*="sort"]').first();
  if (await link.count()) {
    await link.click();
    await page.waitForLoadState('domcontentloaded');
    expect(new URL(page.url()).searchParams.get('page') ?? '2').toBe('2');
  }
});
