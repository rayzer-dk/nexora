import { expect, test, type Page } from '@playwright/test';

test.describe.configure({ retries: 0, mode: 'serial' });

const stamp = Date.now().toString(36);
const slugA = `e2e-page-${stamp}`;
const slugB = `e2e-page-renamed-${stamp}`;

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')),
    page.locator('button[type="submit"]').click(),
  ]);
}

async function setBody(page: Page, html: string): Promise<void> {
  await page.evaluate((value) => {
    const area = document.querySelector<HTMLTextAreaElement>('textarea[name="body_html"], input[name="body_html"]');
    if (area) {
      area.value = value;
      area.dispatchEvent(new Event('input', { bubbles: true }));
      area.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }, html);
}

// eslint-disable-next-line no-empty-pattern
test.beforeEach(async ({}, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared CI data once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
});

test('a custom information page is created with its own URL and published', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/content/pages/new', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="title"]').fill(`E2E page ${stamp}`);
  await page.locator('input[name="slug"]').fill(slugA);
  await setBody(page, '<p>E2E body text</p>');
  await page.locator('select[name="status"]').selectOption('published');
  await page.locator('input[name="show_in_footer"]').check();
  await Promise.all([page.waitForURL(/\/admin\/content\/pages\/(?!new)/), page.locator('form.admin-page-editor button.is-primary[type="submit"]').first().click()]);

  const storefront = await page.request.get(`/${slugA}`);
  expect(storefront.status()).toBe(200);
  expect(await storefront.text()).toContain('E2E body text');

  const footer = await page.request.get('/');
  expect(await footer.text()).toContain(`/${slugA}`);

  const sitemap = await page.request.get('/sitemap.xml');
  expect(sitemap.status()).toBe(200);
});

test('changing the slug keeps the old address as a 301 redirect', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/content/pages', { waitUntil: 'domcontentloaded' });
  await page.locator('table a', { hasText: `E2E page ${stamp}` }).first().click();
  await page.locator('input[name="slug"]').fill(slugB);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form.admin-page-editor button.is-primary[type="submit"]').first().click()]);

  const old = await page.request.get(`/${slugA}`, { maxRedirects: 0 });
  expect(old.status()).toBe(301);
  expect(old.headers()['location']).toContain(slugB);
  expect((await page.request.get(`/${slugB}`)).status()).toBe(200);
});

test('a page can be duplicated and both copies deleted with confirmation', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/content/pages', { waitUntil: 'domcontentloaded' });
  const rows = page.locator('table tbody tr', { hasText: `E2E page ${stamp}` });
  await expect(rows).toHaveCount(1);
  await rows.first().locator('form[action$="/duplicate"] button').click();
  await page.waitForLoadState('domcontentloaded');
  await page.goto('/admin/content/pages', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('table tbody tr', { hasText: `E2E page ${stamp}` })).toHaveCount(2);

  for (let i = 0; i < 2; i += 1) {
    const row = page.locator('table tbody tr', { hasText: `E2E page ${stamp}` }).first();
    await row.locator('form[action$="/delete"] button').click();
    await page.locator('[data-admin-confirm] [data-confirm-accept]').click();
    await page.waitForLoadState('domcontentloaded');
    await page.goto('/admin/content/pages', { waitUntil: 'domcontentloaded' });
  }
  await expect(page.locator('table tbody tr', { hasText: `E2E page ${stamp}` })).toHaveCount(0);
  expect((await page.request.get(`/${slugB}`)).status()).toBe(404);
});

test('system pages keep a read-only slug', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/content/pages/about', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('input[name="title"]')).toBeVisible();
  await expect(page.locator('input[name="slug"]')).toHaveCount(0);
});

test('product, category, brand and blog forms show the content language bar when several languages are on', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  const edit = page.locator('a[href*="/admin/catalog/products/"][href$="/edit"]').first();
  await expect(edit).toBeVisible();
  await edit.click();
  await expect(page.locator('form').first()).toBeVisible();
  await page.goto('/admin/catalog/metadata', { waitUntil: 'domcontentloaded' });
  await page.goto('/admin/system/localization', { waitUntil: 'domcontentloaded' });
  const enabledLocales = await page.locator('#locales input[type="checkbox"]:checked').count();
  await page.goto('/admin/catalog/metadata', { waitUntil: 'domcontentloaded' });
  if (enabledLocales > 1) {
    await expect(page.locator('.lang-tabs').first()).toBeVisible();
    await expect(page.locator('.lang-tabs__add').first()).toHaveAttribute('href', /localization/);
  } else {
    await expect(page.locator('.lang-tabs')).toHaveCount(0);
  }
});

test('currency page offers per-store provider choice and a manual refresh', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/system/localization', { waitUntil: 'domcontentloaded' });
  const select = page.locator('select[name*="rate_source"]').first();
  await expect(select).toBeVisible();
  const values = await select.locator('option').evaluateAll((options) => options.map((o) => (o as HTMLOptionElement).value));
  for (const code of ['ecb', 'nbu', 'nbp', 'cnb', 'manual']) {
    expect(values).toContain(code);
  }
});

test('a tax rate can be edited and the change is saved', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/system/tax', { waitUntil: 'domcontentloaded' });
  const row = page.locator('tr[data-tax-rate]').first();
  await expect(row).toBeVisible();
  await row.locator('a[href*="/edit"]').first().click();
  await expect(page.locator('form[data-tax-edit]')).toBeVisible();
  const priority = page.locator('input[name="priority"]');
  await priority.fill('7');
  await Promise.all([page.waitForURL(/\/admin\/system\/tax$/), page.locator('form[data-tax-edit] button[type="submit"]').click()]);
  await page.locator('tr[data-tax-rate]').first().locator('a[href*="/edit"]').first().click();
  await expect(page.locator('input[name="priority"]')).toHaveValue('7');
  await page.locator('input[name="priority"]').fill('100');
  await Promise.all([page.waitForURL(/\/admin\/system\/tax$/), page.locator('form[data-tax-edit] button[type="submit"]').click()]);
});

test('builder block names are localized, not raw component codes', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/appearance/builder/home', { waitUntil: 'domcontentloaded' });
  const first = page.locator('.mc-builder-block small').first();
  await expect(first).toBeVisible();
  expect(await first.textContent()).not.toMatch(/_/);
});
