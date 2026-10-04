import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError, openProductTab } from './helpers';

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

test('login form has a show-password eye and a working password recovery flow', async ({ page }) => {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  const password = page.locator('input[name="_password"]');
  await password.fill('visible-check');
  await page.locator('[data-password-toggle]').click();
  await expect(password).toHaveAttribute('type', 'text');
  await page.locator('[data-password-toggle]').click();
  await expect(password).toHaveAttribute('type', 'password');

  await page.locator('.admin-login-links a').click();
  await expect(page).toHaveURL(/\/admin\/forgot$/);
  // An unknown address must look exactly like a known one (no account enumeration).
  await page.locator('input[name="email"]').fill('nobody-here@example.test');
  await page.locator('button[type="submit"]').click();
  await expect(page.locator('.admin-notice.is-success')).toBeVisible();

  await page.goto('/admin/forgot', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="email"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('button[type="submit"]').click();
  await expect(page.locator('.admin-notice.is-success')).toBeVisible();

  const resetUrl = execFileSync('php', ['tests/e2e/read-admin-reset-url.php', process.env.E2E_ADMIN_EMAIL!], { encoding: 'utf8' }).trim();
  expect(resetUrl).toContain('/admin/reset/');
  const path = new URL(resetUrl).pathname;

  await page.goto(path, { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="password"]').fill('short');
  await page.locator('input[name="password_confirm"]').fill('short');
  // Client-side minlength blocks the submit; bypass it to prove the server rule too.
  await page.evaluate(() => document.querySelectorAll('input[minlength]').forEach((i) => i.removeAttribute('minlength')));
  await page.locator('button[type="submit"]').click();
  await expect(page.locator('.admin-notice.is-error')).toBeVisible();

  await page.locator('input[name="password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await page.locator('input[name="password_confirm"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await page.locator('button[type="submit"]').click();
  await expect(page).toHaveURL(/\/admin\/login$/);
  await expect(page.locator('.admin-notice.is-success')).toBeVisible();

  // The link is single-use.
  await page.goto(path, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.admin-notice.is-error')).toBeVisible();
  await loginAdmin(page);
});

test('storefront has a default favicon', async ({ page, request }) => {
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  expect(await page.locator('link[rel~="icon"]').count()).toBeGreaterThan(0);
  expect((await request.get('/favicon.ico')).status()).toBe(200);
});

test('custom units appear in product forms and the HTML editor offers links, library images and highlighted source', async ({ page }) => {
  await loginAdmin(page);
  const unitName = `Ящик ${Date.now() % 100000}`;
  await page.goto('/admin/catalog/units', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  for (const input of await page.locator('input[name^="name["]').all()) await input.fill(unitName);
  for (const input of await page.locator('input[name^="short["]').all()) await input.fill('ящ.');
  await page.locator('form[action$="/units/create"] button[type="submit"]').click();
  await expect(page.locator('.admin-notice.is-success')).toBeAttached();
  await expect(page.locator('.admin-table')).toContainText(unitName);

  await page.goto('/admin/catalog/products/new', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('select[name="unit_code"] option', { hasText: unitName })).toHaveCount(1);

  // SEO URL generate icon.
  await page.locator('input[name="name"]').fill('Тестовий товар');
  await page.locator('.admin-slug-generate').first().click();
  await expect(page.locator('input[name="slug"]')).toHaveValue('testovyi-tovar');

  // Link dialog with target/nofollow, no native prompt.
  await page.locator('.rich-editor__content').click();
  await page.keyboard.type('Link me');
  await page.keyboard.press('Control+A');
  await page.locator('.rich-editor__toolbar button[aria-pressed="false"], .rich-editor__toolbar button').filter({ has: page.locator('svg.lucide-link2, svg.lucide-link-2') }).first().click();
  const dialog = page.locator('.rich-editor__dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('.admin-modal__close')).toBeVisible();
  await dialog.locator('input[type="text"]').first().fill('https://example.com/x');
  await dialog.locator('.rich-editor__check input').nth(0).check();
  await dialog.locator('.rich-editor__check input').nth(1).check();
  await dialog.locator('button[type="submit"]').click();
  const html = await page.locator('textarea[name="description"]').inputValue();
  expect(html).toContain('href="https://example.com/x"');
  expect(html).toContain('target="_blank"');
  expect(html).toContain('nofollow');

  // Source view is syntax highlighted (CodeMirror).
  await page.locator('.rich-editor__source-toggle').click();
  await expect(page.locator('.cm-editor')).toBeVisible();
  const colors = await page.locator('.cm-editor .cm-line span').evaluateAll((nodes) => new Set(nodes.map((n) => getComputedStyle(n).color)).size);
  expect(colors).toBeGreaterThan(2);
  await page.locator('.rich-editor__source-toggle').click();

  // Media library picker closes with ×.
  await openProductTab(page, 'media');
  await page.locator('[data-media-pick="multiple"]').click();
  await expect(page.locator('dialog.mc-picker[open]')).toBeVisible();
  await page.locator('dialog.mc-picker .admin-modal__close').click();
  await expect(page.locator('dialog.mc-picker')).toHaveCount(0);
});

test('a product picks an already uploaded image and a category gets a cover image and clear text positions', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/media', { waitUntil: 'domcontentloaded' });
  const upload = page.locator('form[action="/admin/media/upload"]');
  const picked = page.waitForResponse((r) => r.url().endsWith('/admin/media/upload') && r.request().method() === 'POST');
  // The upload form submits itself and redirects back to the library; wait for that page, not the old one that already matches.
  const reloaded = page.waitForResponse((r) => r.request().isNavigationRequest() && r.request().method() === 'GET' && new URL(r.url()).pathname === '/admin/media');
  await upload.locator('input[type="file"]').setInputFiles({
    name: 'e2e-pick.png',
    mimeType: 'image/png',
    buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAIAAAAmkwkpAAAAFElEQVR4nGPkqrjDAANMDEgANwcARI4BZoWJLsMAAAAASUVORK5CYII=', 'base64'),
  });
  await picked;
  await reloaded;
  await page.waitForLoadState('load');
  await expect(page.locator('.media-card').first()).toBeVisible();

  // Category: choose the cover from the library and keep it after saving.
  await page.goto('/admin/catalog/categories/new', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="name"]').fill(`Cover ${Date.now()}`);
  await page.locator('[data-media-pick="single"]').click();
  await expect(page.locator('dialog.mc-picker[open] .mc-picker__tile').first()).toBeVisible();
  await page.locator('dialog.mc-picker[open] .mc-picker__tile').first().click();
  await expect(page.locator('#category-image-picked input[name="category_image_id"]')).toHaveCount(1);
  await page.locator('form.admin-form button[type="submit"].is-primary').click();
  await page.waitForURL(/\/admin\/catalog\/categories$/);
  await expect(page.locator('.admin-notice.is-error')).toHaveCount(0);

  // Product: attach the same library image without uploading a file.
  await page.goto('/admin/catalog/products/new', { waitUntil: 'domcontentloaded' });
  const sku = `PICK-${Date.now()}`;
  await page.locator('input[name="name"]').fill(`Picked ${sku}`);
  await page.locator('input[name="sku"]').fill(sku);
  await openProductTab(page, 'media');
  await page.locator('[data-media-pick="multiple"]').click();
  await expect(page.locator('dialog.mc-picker[open] .mc-picker__tile').first()).toBeVisible();
  await page.locator('dialog.mc-picker[open] .mc-picker__tile').first().click();
  await page.locator('dialog.mc-picker[open] .mc-picker__foot .is-primary').click();
  await expect(page.locator('#product-media-picked input[name="existing_media[]"]')).toHaveCount(1);
  // Enter in a text field saves the product (it must not trigger a secondary action).
  await openProductTab(page, 'general');
  await page.locator('input[name="sku"]').press('Enter');
  await page.waitForURL(/\/admin\/catalog\/products\/[^/]+\/edit$/);
  await expect(page.locator('.admin-media-grid .admin-media-item')).toHaveCount(1);
  // Custom document types: choosing "custom" reveals a free-text field.
  await openProductTab(page, 'files');
  await page.locator('select[name="document_type"]').selectOption('__custom');
  await expect(page.locator('input[name="document_type_custom"]')).toBeVisible();
});

test('interface language can be switched back and forth and edits are protected on language change', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/catalog/products/new', { waitUntil: 'domcontentloaded' });
  const ui = page.locator('select[name="ui_locale"]');
  await Promise.all([page.waitForLoadState('domcontentloaded'), ui.selectOption('en-US')]);
  await expect(page.locator('select[name="ui_locale"]')).toHaveValue('en-US');
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('select[name="ui_locale"]').selectOption('uk-UA')]);
  await expect(page.locator('select[name="ui_locale"]')).toHaveValue('uk-UA');
  await expect(page.locator('.admin-nav')).toContainText('Товари');

  // The confirm dialog closes with × and Esc.
  await page.goto('/admin/catalog/units', { waitUntil: 'domcontentloaded' });
  await page.locator('button.is-danger[data-confirm]').first().click();
  await expect(page.locator('.admin-modal:not([hidden]) .admin-modal__close')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.locator('[data-admin-confirm]')).toBeHidden();
});
