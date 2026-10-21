import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError, loginAdmin as login } from './helpers';

// Catalog lists: product counts with subcategories, the stock column (quick edit + undo), bulk "zero stock", switch-only status.

async function undoLast(page: Page): Promise<void> {
  const undo = page.locator('form.admin-undo[data-undo-form]');
  await expect(undo).toBeVisible();
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && /\/admin\/undo\/\d+$/.test(r.url())),
    undo.locator('button[type="submit"]').click(),
  ]);
  await page.waitForLoadState('domcontentloaded');
  await expectNoServerError(page);
}

async function setQuickStock(page: Page, url: string, value: string): Promise<void> {
  const input = page.locator(`[data-quick-stock][data-url="${url}"] input`);
  await input.fill(value);
  const saved = page.waitForResponse((r) => r.url().endsWith('/quick-stock') && r.request().method() === 'POST');
  await input.press('Enter');
  const response = await saved;
  expect(response.status()).toBe(200);
  expect((await response.json()).ok).toBe(true);
}

test('categories: products with subcategories, a link to the filtered list, switch-only status', async ({ page }) => {
  await login(page);
  await page.goto('/admin/catalog/categories', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);

  const links = page.locator('a[data-category-count]');
  expect(await links.count()).toBeGreaterThan(0);
  // A parent counts its subcategories: its number is never below the sum shown "in the category itself".
  const first = links.first();
  const total = Number(await first.textContent());
  const hint = (await first.getAttribute('title')) ?? '';
  const direct = Number(hint.match(/(\d+)\s*$/)?.[1] ?? 'NaN');
  expect(total).toBeGreaterThan(0);
  expect(direct).toBeLessThanOrEqual(total);
  const href = (await first.getAttribute('href')) ?? '';
  expect(href).toMatch(/^\/admin\/catalog\/products\?category\[\]=\d+$/);

  // The status column holds only the switch; the state is in title / aria-label.
  const statusCell = page.locator('table.admin-table tbody tr').first().locator('td.admin-quick-status-cell');
  await expect(statusCell.locator('.admin-badge')).toHaveCount(0);
  await expect(statusCell.locator('input[role="switch"]')).toHaveAttribute('title', /.+: .+/);
  await expect(statusCell).not.toContainText(/\S/);

  // The search view shows the same numbers (it used to show a dash).
  const rowName = (await page.locator('table.admin-table tbody tr').filter({ has: first }).first().locator('strong').first().textContent())?.trim() ?? '';
  await page.goto(`/admin/catalog/categories?search=${encodeURIComponent(rowName)}`, { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const found = page.locator('table.admin-table tbody tr').filter({ hasText: rowName }).first().locator('[data-category-count]');
  await expect(found).toHaveText(String(total));

  // The link opens the product list filtered by the category (with its subcategories): the same number of products.
  await page.goto(href, { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const rows = page.locator('table.admin-table tbody tr:has(input[data-row-check])');
  if (total <= 25) await expect(rows).toHaveCount(total);
  else expect(await rows.count()).toBe(25);
});

test('products: stock column, quick edit with validation, Esc and undo', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating data runs once.');
  await login(page);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('table.admin-table thead th[data-sort-key="stock"]')).toBeVisible();
  await expect(page.locator('.admin-column-picker input[value="stock"]')).toBeAttached();

  // Status: only the switch (or the archive icon), no text badge.
  const statusCells = page.locator('table.admin-table tbody td.admin-quick-status-cell');
  expect(await statusCells.count()).toBeGreaterThan(0);
  await expect(statusCells.locator('.admin-badge')).toHaveCount(0);

  const box = page.locator('[data-quick-stock]').first();
  await expect(box).toBeVisible();
  const url = (await box.getAttribute('data-url'))!;
  const input = box.locator('input');
  const original = await input.inputValue();
  const next = original === '17' ? '18' : '17';

  // Letters and negative numbers are not sent; the field is highlighted and the old value is kept on the server.
  let sent = 0;
  page.on('request', (r) => { if (r.url().endsWith('/quick-stock')) sent += 1; });
  for (const bad of ['abc', '-3']) {
    await input.fill(bad);
    await input.press('Enter');
    await expect(box).toHaveClass(/is-invalid/);
  }
  expect(sent).toBe(0);
  await input.press('Escape');
  await expect(input).toHaveValue(original);
  await expect(box).not.toHaveClass(/is-invalid/);

  await setQuickStock(page, url, next);
  await expect(page.locator('[data-admin-toast-stack]')).toContainText(/Залишок збережено|Stock saved/);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.locator(`[data-quick-stock][data-url="${url}"] input`)).toHaveValue(next);

  // The quick change is remembered for undo, like the price.
  await undoLast(page);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  await expect(page.locator(`[data-quick-stock][data-url="${url}"] input`)).toHaveValue(original);
});

test('products: bulk "zero stock" asks with the count, zeroes and can be undone', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating data runs once.');
  await login(page);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  const box = page.locator('[data-quick-stock]').first();
  const url = (await box.getAttribute('data-url'))!;
  const original = await box.locator('input').inputValue();
  await setQuickStock(page, url, '6');
  await page.reload({ waitUntil: 'domcontentloaded' });

  const row = page.locator('table.admin-table tbody tr').filter({ has: page.locator(`[data-quick-stock][data-url="${url}"]`) });
  await row.locator('input[data-row-check]').check();
  await page.locator('select[name="action"]').selectOption('zero_stock');
  await page.locator('button[data-bulk-submit]').click();
  const message = page.locator('[data-confirm-message]');
  await expect(message).toBeVisible();
  await expect(message).toContainText('(1)');
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith('/admin/commerce/products/bulk')),
    page.locator('[data-confirm-accept]').click(),
  ]);
  await page.waitForLoadState('domcontentloaded');
  await expectNoServerError(page);
  await expect(page.locator('.admin-notice.is-success')).toContainText(/1/);
  await expect(page.locator(`[data-quick-stock][data-url="${url}"] input`)).toHaveValue('0');

  await undoLast(page);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  await expect(page.locator(`[data-quick-stock][data-url="${url}"] input`)).toHaveValue('6');

  // Put the product back as it was before the test.
  await setQuickStock(page, url, original === '' ? '0' : original);
});

test('products: a product with several variants shows the total and a link to the variants', async ({ page }) => {
  await login(page);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  const variants = page.locator('a.admin-quick-stock-variants');
  test.skip((await variants.count()) === 0, 'No product with several variants on the first page.');
  await expect(variants.first()).toHaveAttribute('href', /\/edit#variants$/);
  await expect(variants.first()).toHaveText(/\(\d+\)/);
  const cell = variants.first().locator('xpath=ancestor::td');
  await expect(cell.locator('input')).toHaveCount(0);
});
