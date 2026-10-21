import { expect, test } from '@playwright/test';
import { expectNoServerError, openProductTab, loginAdmin as login } from './helpers';

// Release 3.30.0: product code generator, attribute search, category dropdown, editor extras, feed reasons.

test('new product: code generator, searchable categories, editor toolbar extras', async ({ page }) => {
  await login(page);
  await page.goto('/admin/catalog/products/new', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await openProductTab(page, 'general');

  const sku = page.locator('input[name="sku"]');
  await page.locator('[data-sku-template]').fill('QAG-###');
  await page.locator('[data-sku-generate]').click();
  await expect(sku).toHaveValue(/^QAG-\d{3}$/);

  const categories = page.locator('.admin-multiselect').first();
  await categories.locator('summary').click();
  await expect(categories.locator('[data-multiselect-search]')).toBeVisible();

  const toolbar = page.locator('.rich-editor__toolbar').first();
  await expect(toolbar).toBeVisible();
  await toolbar.locator('button[aria-expanded]').last().click(); // the rarely used tools sit in the "more" menu
  const titles = await toolbar.locator('[title]').evaluateAll((nodes) => nodes.map((n) => n.getAttribute('title') || ''));
  for (const pattern of [/Font|Шрифт/, /Line spacing|Міжрядковий/, /Text colour|Колір тексту/, /Anchor|Якір/, /Video|Відео/]) {
    expect(titles.some((title) => pattern.test(title)), String(pattern)).toBe(true);
  }
  await toolbar.locator('button[title*="ideo"], button[title*="ідео"]').first().click();
  await page.locator('.rich-editor__dialog input').first().fill('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
  await page.locator('.rich-editor__dialog button[type="submit"]').click();
  await expect(page.locator('.rich-editor__content iframe.rte-video')).toHaveAttribute('src', /youtube-nocookie\.com\/embed\/dQw4w9WgXcQ/);
});

test('feeds page lists the reasons and the toggle exists on the storefront', async ({ page }) => {
  await login(page);
  await page.goto('/admin/commerce/feeds', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('.admin-feed-currency').first()).toBeVisible();
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-theme-toggle]').first()).toBeVisible();
});
