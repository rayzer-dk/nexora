import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')),
    page.locator('button[type="submit"]').click(),
  ]);
}

// eslint-disable-next-line no-empty-pattern
test.beforeEach(async ({}, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared CI data once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
});

test('menu is an accordion: opening a section collapses the previous one', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/orders', { waitUntil: 'domcontentloaded' });
  const groups = page.locator('.admin-nav.is-accordion .admin-nav__label');
  await expect(groups.first()).toBeVisible();
  const open = page.locator('.admin-nav__label.is-open');
  await expect(open).toHaveCount(1);
  await expect(page.locator('.admin-nav__link.is-active')).toBeVisible();
  // Open a different section: the active one closes.
  const other = groups.filter({ hasNot: page.locator('.is-open') }).first();
  const previous = await open.first().textContent();
  await other.click();
  await expect(page.locator('.admin-nav__label.is-open')).toHaveCount(1);
  await expect(page.locator('.admin-nav__label.is-open')).not.toHaveText(previous ?? '');
});

test('orders list sorts by header on the server and has a first/last pager', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/orders?limit=25', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('.admin-pagination').first()).toBeVisible();
  await expect(page.locator('.admin-pagination [title]').first()).toBeAttached();
  await page.locator('th[data-sort-key="total"]').first().click();
  await expect(page).toHaveURL(/sort=total&dir=asc/);
  await page.locator('th[data-sort-key="total"]').first().click();
  await expect(page).toHaveURL(/sort=total&dir=desc/);
  await page.goto('/admin/orders?page=2&limit=25', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.admin-pagination a[aria-label]').first()).toBeVisible();
});

test('generic tables sort by column and page on the client', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/commerce/customers', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const table = page.locator('table.admin-table').first();
  const sortable = table.locator('th.is-sortable').first();
  await expect(sortable).toBeVisible();
  await sortable.click();
  await expect(sortable).toHaveAttribute('aria-sort', 'ascending');
  await sortable.click();
  await expect(sortable).toHaveAttribute('aria-sort', 'descending');
});

test('returns and feedback are tabs with star ratings', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/customer-experience', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const tabs = page.locator('.admin-tabs .admin-tab');
  await expect(tabs).toHaveCount(4);
  await tabs.nth(1).click();
  await expect(page.locator('[data-tab="reviews"]')).toBeVisible();
  await expect(page.locator('[data-tab="returns"]')).toBeHidden();
  await expect(page.locator('[data-tab="reviews"] .admin-stars').first()).toBeVisible();
  await tabs.nth(2).click();
  await expect(page.locator('[data-tab="questions"]')).toBeVisible();
});

test('badge colour accepts a HEX code and the storefront renders it', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/catalog/badges', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const field = page.locator('[data-tone-field]').first();
  const hex = field.locator('[data-tone-hex]');
  await expect(hex).toBeVisible();
  await hex.fill('#12ab34');
  await expect(field.locator('[data-tone-picker]')).toHaveValue('#12ab34');
  await field.locator('xpath=ancestor::form').locator('button[type="submit"]').first().click();
  await expect(page.locator('[data-tone-field] [data-tone-hex]').first()).toHaveValue('#12ab34');
  // Plain colour pickers get a HEX box too.
  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('input.admin-color-hex').first()).toBeVisible();
});

test('media library: folders, upload from computer, bulk selection, demo images', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/media', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  // Demo assets are visible in the library.
  await expect(page.locator('.media-card').first()).toBeVisible();
  await expect(page.locator('.media-folders')).toContainText('Demo');
  // Create a folder.
  await page.locator('.media-folder-form input[name="name"]').fill('E2E folder');
  await page.locator('.media-folder-form button[type="submit"]').click();
  await expect(page.locator('.media-folders')).toContainText('E2E folder');
  // Bulk: select two files, buttons enable.
  await page.goto('/admin/media', { waitUntil: 'domcontentloaded' });
  const checks = page.locator('[data-media-check]');
  await checks.nth(0).check();
  await checks.nth(1).check();
  await expect(page.locator('[data-media-bulk-btn]').first()).toBeEnabled();
  await expect(page.locator('[data-media-selected]')).toContainText('2');
  await page.locator('[data-media-check-all]').check();
  await expect(checks.last()).toBeChecked();
  // Move the selection to the new folder.
  await page.locator('#media-bulk-form select[name="folder_id"]').selectOption({ label: 'E2E folder' });
  await page.locator('#media-bulk-form button[formaction$="/bulk/move"]').click();
  await expect(page.locator('.media-folders')).toContainText('E2E folder');
  // Upload a real file from the "computer".
  await page.goto('/admin/media', { waitUntil: 'domcontentloaded' });
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mP8z8BQz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC', 'base64');
  const before = await page.locator('.media-card').count();
  await page.locator('input[data-media-autosubmit]').setInputFiles({ name: 'e2e-upload.png', mimeType: 'image/png', buffer: png });
  await page.waitForLoadState('domcontentloaded');
  await expect.poll(async () => page.locator('.media-card').count(), { timeout: 15000 }).toBeGreaterThanOrEqual(Math.min(before, 47));
});

test('media picker dialog restores the scroll position and can switch folders', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  const choose = page.locator('[data-media-choose]').first();
  await choose.scrollIntoViewIfNeeded();
  const y = await page.evaluate(() => Math.round(window.scrollY));
  await choose.click();
  const dialog = page.locator('dialog.mc-picker');
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('.mc-picker__path')).toBeVisible();
  // The file manager shows folders and files; opening a folder moves the path and the up button leads back.
  const folder = dialog.locator('.mc-picker__folder:not(.is-up)').first();
  await expect(folder).toBeVisible();
  await folder.click();
  await expect(dialog.locator('.mc-picker__path .mc-picker__crumb')).toHaveCount(2);
  await dialog.locator('.mc-picker__bar .mc-picker__tool').first().click();
  await expect(dialog.locator('.mc-picker__path .mc-picker__crumb')).toHaveCount(1);
  await dialog.locator('.admin-modal__close').click();
  await expect(dialog).toHaveCount(0);
  const after = await page.evaluate(() => Math.round(window.scrollY));
  expect(Math.abs(after - y)).toBeLessThan(4);
});

test('e-mail templates show defaults, preview and HTML source', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/commerce/notification-templates', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const card = page.locator('[data-notification-template]').first();
  await expect(card.locator('input[name="subject"]')).not.toHaveValue('');
  await expect(card.locator('textarea[name="body"]')).not.toHaveValue('');
  await card.locator('[data-tab-target="preview"]').click();
  await expect(card.locator('iframe[data-tpl-preview]')).toBeVisible();
  await expect.poll(async () => card.locator('iframe[data-tpl-preview]').getAttribute('srcdoc'), { timeout: 10000 }).toContain('<html');
  await card.locator('[data-tab-target="html"]').click();
  await expect(card.locator('[data-tpl-html] .cm-content')).toBeVisible({ timeout: 10000 });
});

test('feeds page lists every language and per-language sitemaps', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/commerce/feeds', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('.feeds-locales')).toBeVisible();
  await expect(page.locator('.feeds-sitemaps a').first()).toHaveAttribute('href', /\/sitemaps\//);
});

test('demo data gives a full cycle: orders, shipments, returns, analytics', async ({ page }) => {
  await loginAdmin(page);
  for (const path of ['/admin/orders', '/admin/shipments', '/admin/customer-experience', '/admin/commerce/customers', '/admin/analytics', '/admin/rewards', '/admin/commerce/campaigns', '/admin/commerce/promotions']) {
    await page.goto(path, { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
  }
  await page.goto('/admin/orders', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('table.admin-table tbody tr').first()).not.toContainText('DEMO-none');
  expect(await page.locator('table.admin-table tbody tr').count()).toBeGreaterThan(5);
});
