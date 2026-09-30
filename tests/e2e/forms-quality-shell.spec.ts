import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

const suffix = Date.now();
const slug = `e2e-form-${suffix}`;

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
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');
});

test('the admin sidebar collapses to icons, remembers it and expands again', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin', { waitUntil: 'domcontentloaded' });
  const label = page.locator('.admin-nav__link:not(.admin-nav__collapse) > span').first();
  await expect(label).toBeVisible();
  const wide = (await page.locator('.admin-sidebar').boundingBox())!.width;

  await page.locator('[data-sidebar-collapse]').click();
  await expect(page.locator('html')).toHaveAttribute('data-sidebar', 'collapsed');
  await expect(label).toBeHidden();
  const narrow = (await page.locator('.admin-sidebar').boundingBox())!.width;
  expect(narrow).toBeLessThan(wide / 2);
  // every link keeps an icon and a tooltip
  await expect(page.locator('.admin-nav__link[title] .ui-icon').first()).toBeVisible();

  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.locator('html')).toHaveAttribute('data-sidebar', 'collapsed');

  await page.locator('[data-sidebar-collapse]').click();
  await expect(page.locator('html')).not.toHaveAttribute('data-sidebar', 'collapsed');
  await expect(label).toBeVisible();
});

test('navigation icons and dashboard cards carry colour tones', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin', { waitUntil: 'domcontentloaded' });
  const tones = await page
    .locator('.admin-nav__link[data-tone]')
    .evaluateAll((links) => new Set(links.map((l) => l.getAttribute('data-tone'))).size);
  expect(tones).toBeGreaterThanOrEqual(4);
  const borders = await page
    .locator('.dash-kpis > .dash-kpi')
    .evaluateAll((cards) => new Set(cards.map((c) => getComputedStyle(c).borderTopColor)).size);
  expect(borders).toBeGreaterThanOrEqual(3);
});

test('the quality monitor scores the site and every check is translated', async ({ page }) => {
  await loginAdmin(page);
  const response = await page.goto('/admin/system/quality', { waitUntil: 'domcontentloaded' });
  expect(response?.status()).toBe(200);
  await expectNoServerError(page);
  const score = Number(await page.locator('[data-quality-score]').getAttribute('data-quality-score'));
  expect(score).toBeGreaterThanOrEqual(0);
  expect(score).toBeLessThanOrEqual(100);
  expect(await page.locator('[data-quality-check]').count()).toBeGreaterThanOrEqual(15);
  await expect(page.locator('[data-quality-group]')).toHaveCount(6);
  // a missing translation would show the raw key
  expect(await page.locator('.admin-content').innerText()).not.toMatch(/admin\.quality\./);
  // debug is off in the test server, so that check must be green
  await expect(page.locator('[data-quality-check="debug_off"]')).toHaveAttribute('data-status', 'ok');
});

test('a form is built, published, answered by a visitor and the answer is read, exported and removed', async ({
  page,
  browser,
}, testInfo) => {
  await loginAdmin(page);
  await page.goto('/admin/content/forms/new', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await page.locator('input[name="name"]').fill(`E2E form ${suffix}`);
  await page.locator('input[name="slug"]').fill(slug);
  await page.locator('select[name="status"]').selectOption('active');
  await page.locator('input[name="success_message"]').fill('E2E thank you');
  await page.locator('input[name="fields[0][label]"]').fill('Your name');
  await page.locator('input[name="fields[0][required]"]').check();
  await page.locator('input[name="fields[1][label]"]').fill('Topic');
  await page.locator('select[name="fields[1][type]"]').selectOption('select');
  await page.locator('textarea[name="fields[1][options]"]').fill('Sales\nSupport');
  await page.locator('input[name="fields[2][label]"]').fill('Details');
  await page.locator('select[name="fields[2][type]"]').selectOption('textarea');
  await Promise.all([
    page.waitForURL(/\/admin\/content\/forms\/\d+$/),
    page.locator('[data-form-editor] button[type="submit"]').first().click(),
  ]);
  await expect(page.locator('.admin-notice.is-success').first()).toContainText('Форму збережено');

  // invalid: a select without options is refused, nothing is lost
  await page.locator('input[name="fields[3][label]"]').fill('Broken');
  await page.locator('select[name="fields[3][type]"]').selectOption('radio');
  await page.locator('[data-form-editor] button[type="submit"]').first().click();
  await expect(page.locator('.admin-notice.is-error').first()).toContainText('варіанти');

  const visitor = await browser.newContext({ baseURL: testInfo.project.use.baseURL });
  const shop = await visitor.newPage();
  const missing = await shop.goto('/forms/does-not-exist', { waitUntil: 'domcontentloaded' });
  expect(missing?.status()).toBe(404);
  await shop.goto(`/forms/${slug}`, { waitUntil: 'domcontentloaded' });
  await expect(shop.locator('h1')).toContainText(`E2E form ${suffix}`);
  await shop.locator('input[name="f1"]').fill('=HYPERLINK("http://x")');
  await shop.locator('select[name="f2"]').selectOption('Support');
  await shop.locator('textarea[name="f3"]').fill('Hello from e2e');
  // the anti-spam guard rejects answers sent within a second of rendering the form
  await shop.waitForTimeout(1300);
  await shop.locator('[data-custom-form] button[type="submit"]').click();
  // the AJAX submit resets the form on success; the answer itself is verified in the admin below
  await expect(shop.locator('input[name="f1"]')).toHaveValue('', { timeout: 15000 });
  await visitor.close();

  await page.goto('/admin/content/forms', { waitUntil: 'domcontentloaded' });
  const row = page.locator(`[data-form-row="${slug}"]`);
  await expect(row).toContainText('/forms/' + slug);
  await row.locator('a[href$="/submissions"], td a').nth(1).click();
  await expect(page.locator('[data-submission]')).toHaveCount(1);
  await expect(page.locator('.form-answers')).toContainText('Hello from e2e');
  await expect(page.locator('.form-answers')).toContainText('Support');

  const exportHref = await page.locator('a[href$="/export"]').getAttribute('href');
  const csv = await page.request.get(exportHref!);
  expect(csv.headers()['content-type']).toContain('text/csv');
  const body = await csv.text();
  expect(body).toContain('Hello from e2e');
  // spreadsheet formula injection is neutralised
  expect(body).toContain(`'=HYPERLINK`);
  expect(body).not.toMatch(/,=HYPERLINK/);

  await page.locator('[data-submission] form[action$="/status"] button').click();
  await expect(page.locator('[data-submission] .admin-badge').first()).toBeVisible();

  await page.goto('/admin/content/forms', { waitUntil: 'domcontentloaded' });
  await page.locator(`[data-form-row="${slug}"] form[action$="/delete"] button`).click();
  await page.locator('[data-confirm-accept]').click();
  await expect(page.locator(`[data-form-row="${slug}"]`)).toHaveCount(0);
});

test('recently viewed is available on the catalog and the cart, not only on the product page', async ({ page }) => {
  for (const path of ['/', '/catalog', '/cart']) {
    await page.goto(path, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-recent-list]')).toHaveCount(1);
  }
});

test('the store is not tied to one country: localization offers many currencies and languages', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/system/localization', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const currencies = await page
    .locator('input[name^="currencies"][name$="[enabled]"], input[name*="[enabled]"]')
    .count();
  expect(currencies).toBeGreaterThan(10);
  await page.goto('/admin/system/store', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('select[name="default_currency"] option')).not.toHaveCount(1);
});
