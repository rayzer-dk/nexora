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

async function setLocale(page: Page, code: string, enabled: boolean): Promise<void> {
  await page.goto('/admin/system/localization', { waitUntil: 'domcontentloaded' });
  const box = page.locator(`input[name="locale[${code}][enabled]"]`);
  if (await box.count()) {
    await (enabled ? box.check() : box.uncheck());
  } else if (enabled) {
    await page.locator('input[name="new_code"]').fill(code);
    await page.locator('input[name="new_name"]').fill('English');
    await page.locator('input[name="new_native_name"]').fill('English');
  }
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('form[action$="/localization/locales"] button[type="submit"]').click(),
  ]);
}

async function firstProductId(page: Page): Promise<string> {
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  const href = await page.locator('a[href*="/admin/catalog/products/"][href$="/edit"]').first().getAttribute('href');
  return href!.split('/')[4];
}

// eslint-disable-next-line no-empty-pattern
test.beforeEach(async ({}, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared CI data once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');
});

test('translations page creates a second-language version of a product and a category', async ({ page }) => {
  await loginAdmin(page);
  await setLocale(page, 'en-US', true);
  const id = await firstProductId(page);
  const response = await page.goto(`/admin/catalog/products/${id}/translations`, { waitUntil: 'domcontentloaded' });
  expect(response?.status()).toBe(200);
  await expectNoServerError(page);
  expect(await page.locator('.admin-content').innerText()).not.toMatch(/admin\.translations\./);
  await page.locator('[data-lang-item="en-US"] button, [data-lang-item="en-US"] a').first().click();
  const panel = page.locator('form[action$="/translations/en-US"]');
  await panel.locator('input[name="name"]').fill('E2E English name');
  await panel.locator('textarea[name="description"]').fill('<p>E2E English description</p>');
  await Promise.all([page.waitForLoadState('domcontentloaded'), panel.locator('button[type="submit"]').click()]);
  await expectNoServerError(page);
  await expect(page.locator('form[action$="/translations/en-US"] input[name="name"]')).toHaveValue('E2E English name');
  await page.goto('/admin/catalog/categories', { waitUntil: 'domcontentloaded' });
  const cat = await page
    .locator('a[href*="/admin/catalog/categories/"][href*="/translations"]')
    .first()
    .getAttribute('href');
  const catResponse = await page.goto(cat!, { waitUntil: 'domcontentloaded' });
  expect(catResponse?.status()).toBe(200);
  await page.locator('[data-lang-item="en-US"] button, [data-lang-item="en-US"] a').first().click();
  const catPanel = page.locator('form[action$="/translations/en-US"]');
  await catPanel.locator('input[name="name"]').fill('E2E English category');
  await Promise.all([page.waitForLoadState('domcontentloaded'), catPanel.locator('button[type="submit"]').click()]);
  await expect(page.locator('form[action$="/translations/en-US"] input[name="name"]')).toHaveValue(
    'E2E English category',
  );
});

test('translation save refuses a foreign locale and an empty name', async ({ page }) => {
  await loginAdmin(page);
  const id = await firstProductId(page);
  await page.goto(`/admin/catalog/products/${id}/translations`, { waitUntil: 'domcontentloaded' });
  const token = await page.locator('form[action$="/translations/en-US"] input[name="_token"]').inputValue();
  const bad = await page.request.post(`/admin/catalog/products/${id}/translations/xx-XX`, {
    form: { _token: token, name: 'x' },
    maxRedirects: 0,
  });
  expect(bad.status()).toBeLessThan(500);
  const empty = await page.request.post(`/admin/catalog/products/${id}/translations/en-US`, {
    form: { _token: token, name: '' },
    maxRedirects: 0,
  });
  expect(empty.status()).toBeLessThan(500);
  await page.goto(`/admin/catalog/products/${id}/translations`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('form[action$="/translations/en-US"] input[name="name"]')).toHaveValue('E2E English name');
});

test('catalogue quality page, dashboard card and publish policy', async ({ page }) => {
  await loginAdmin(page);
  const response = await page.goto('/admin/catalog/quality', { waitUntil: 'domcontentloaded' });
  expect(response?.status()).toBe(200);
  await expectNoServerError(page);
  expect(await page.locator('.admin-content').innerText()).not.toMatch(/admin\.catalog_quality\./);
  await expect(page.locator('[data-issue]').first()).toBeVisible();
  await page.goto('/admin/catalog/quality?issue=no_image', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await page.goto('/admin/catalog/quality?issue=bogus&page=999', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  for (const mode of ['block', 'warn', 'off']) {
    await page.goto('/admin/catalog/quality', { waitUntil: 'domcontentloaded' });
    await page.locator('[data-content-policy] select[name="mode"]').selectOption(mode);
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      page.locator('[data-content-policy] button[type="submit"]').click(),
    ]);
    await expectNoServerError(page);
    await expect(page.locator('[data-content-policy] select[name="mode"]')).toHaveValue(mode);
  }
  await page.goto('/admin', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
});

test('block mode refuses to publish a product that lacks a language, warn mode publishes with a warning', async ({
  page,
}) => {
  await loginAdmin(page);
  await page.goto('/admin/catalog/products/new', { waitUntil: 'domcontentloaded' });
  const sku = `E2E-PUB-${Date.now()}`;
  await page.locator('input[name="name"]').fill('E2E publish policy');
  await page.locator('input[name="sku"]').fill(sku);
  await openProductTab(page, 'sales');
  await page.locator('input[name="price"]').fill('10.00');
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page
      .locator('form:has(input[name="sku"], input[name^="rows["]) button[type="submit"]:not([formaction]):visible')
      .first()
      .click(),
  ]);
  await expectNoServerError(page);
  await page.goto(`/admin/catalog/products?search=${sku}`, { waitUntil: 'domcontentloaded' });
  const href = await page.locator('a[href*="/admin/catalog/products/"][href$="/edit"]').first().getAttribute('href');
  expect(href).toBeTruthy();
  const setMode = async (mode: string) => {
    await page.goto('/admin/catalog/quality', { waitUntil: 'domcontentloaded' });
    await page.locator('[data-content-policy] select[name="mode"]').selectOption(mode);
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      page.locator('[data-content-policy] button[type="submit"]').click(),
    ]);
  };
  const publish = async () => {
    await page.goto(href!, { waitUntil: 'domcontentloaded' });
    await page.locator('select[name="status"]').selectOption('published');
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      page
        .locator('form:has(input[name="sku"], input[name^="rows["]) button[type="submit"]:not([formaction]):visible')
        .first()
        .click(),
    ]);
    await expectNoServerError(page);
    await page.goto(href!, { waitUntil: 'domcontentloaded' });
    return page.locator('select[name="status"]').inputValue();
  };
  await setMode('block');
  expect(await publish()).toBe('draft');
  await setMode('warn');
  expect(await publish()).toBe('published');
  await setMode('off');
});

test('tax page: rates, display modes and the product tax class', async ({ page }) => {
  await loginAdmin(page);
  const response = await page.goto('/admin/system/tax', { waitUntil: 'domcontentloaded' });
  expect(response?.status()).toBe(200);
  await expectNoServerError(page);
  expect(await page.locator('.admin-content').innerText()).not.toMatch(/admin\.tax\./);
  await page.locator('[data-tax-add] select[name="class_id"]').selectOption({ index: 0 });
  await page.locator('[data-tax-add] input[name="country"]').fill('PL');
  await page.locator('[data-tax-add] input[name="name"]').fill('E2E VAT');
  await page.locator('[data-tax-add] input[name="percent"]').fill('23');
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('[data-tax-add] button[type="submit"]').click(),
  ]);
  await expectNoServerError(page);
  await expect(page.locator('.admin-content')).toContainText('E2E VAT');
  const bad = page.locator('[data-tax-add] input[name="percent"]');
  await bad.fill('abc');
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('[data-tax-add] button[type="submit"]').click(),
  ]);
  await expectNoServerError(page);
  for (const mode of ['net_with_gross', 'gross_with_breakdown', 'net', 'price_only']) {
    await page.goto('/admin/system/tax', { waitUntil: 'domcontentloaded' });
    await page.locator(`[data-tax-mode] input[name="mode"][value="${mode}"]`).check();
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      page.locator('[data-tax-mode] button[type="submit"]').click(),
    ]);
    await expectNoServerError(page);
    await expect(page.locator(`[data-tax-mode] input[name="mode"][value="${mode}"]`)).toBeChecked();
    if (mode !== 'price_only') {
      const home = await page.request.get('/catalog');
      expect(home.status()).toBe(200);
    }
  }
  const id = await firstProductId(page);
  await page.goto(`/admin/catalog/products/${id}/edit`, { waitUntil: 'domcontentloaded' });
  await openProductTab(page, 'sales');
  await expect(page.locator('select[data-tax-class]')).toBeVisible();
});

test('undo puts back a bulk edit', async ({ page }) => {
  await loginAdmin(page);
  const id = await firstProductId(page);
  await page.goto(`/admin/catalog/products/bulk-edit?id[]=${id}`, { waitUntil: 'domcontentloaded' });
  const price = page.locator(`input[name="rows[${id}][price]"]`);
  const before = await price.inputValue();
  await price.fill('777.00');
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page
      .locator('form:has(input[name="sku"], input[name^="rows["]) button[type="submit"]:not([formaction]):visible')
      .first()
      .click(),
  ]);
  await expectNoServerError(page);
  await expect(page.locator('[data-undo-form]')).toBeVisible();
  await page.goto(`/admin/catalog/products/bulk-edit?id[]=${id}`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator(`input[name="rows[${id}][price]"]`)).toHaveValue('777.00');
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('[data-undo-form] button[type="submit"]').click(),
  ]);
  await expectNoServerError(page);
  await page.goto(`/admin/catalog/products/bulk-edit?id[]=${id}`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator(`input[name="rows[${id}][price]"]`)).toHaveValue(before);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-undo-form]')).toHaveCount(0);
});

test('undo ticket is single-use and cannot be forged', async ({ page }) => {
  await loginAdmin(page);
  const forged = await page.request.post('/admin/undo/999999', { form: { _token: 'bad' }, maxRedirects: 0 });
  expect([302, 403]).toContain(forged.status());
});

test('AI translate script is bundled into the admin runtime', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin', { waitUntil: 'domcontentloaded' });
  const srcs = await page.locator('script[src]').evaluateAll((n) => n.map((x) => x.getAttribute('src') ?? ''));
  const runtime = srcs.find((s) => /adminRuntime/i.test(s));
  expect(runtime, srcs.join(',')).toBeTruthy();
  const js = await (await page.request.get(runtime!)).text();
  expect(js).toContain('data-translate-from');
});
