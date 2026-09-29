import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')), page.locator('button[type="submit"]').click()]);
}

test.beforeEach(async ({}, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared CI data once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');
});

test('product badges: automatic new badge, custom badge by SKU, delete needs confirmation', async ({ page }) => {
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.product-badge[data-badge="new"]').first()).toBeVisible();
  const sku = ((await page.locator('[data-product-card] .catalog-card__meta span').first().innerText()).replace(/^SKU\s*/i, '')).trim();
  expect(sku).not.toBe('');

  await loginAdmin(page);
  await page.goto('/admin/catalog/badges', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('[data-badge-rule="sale"]')).toBeVisible();
  const create = page.locator('section:has(h2) form:has(input[name="kind"][value="manual"])');
  await create.locator('input[name="code"]').fill('e2e-hot');
  await create.locator('input[name="label_default"]').fill('E2E Hot');
  await create.locator('select[name="tone"]').selectOption('success');
  await create.locator('input[name="priority"]').fill('-100');
  await create.locator('textarea[name="skus"]').fill(sku);
  await create.locator('button[type="submit"]').click();
  await expect(page.locator('[data-badge-rule="e2e-hot"]')).toBeVisible();

  // The catalogue query cache has an 8 s TTL, so a new badge appears after at most that long.
  await expect.poll(async () => {
    await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
    return page.locator('.product-badge[data-badge="e2e-hot"]').count();
  }, { timeout: 30_000, intervals: [2_000] }).toBeGreaterThan(0);
  await expect(page.locator('.product-badge[data-badge="e2e-hot"]').first()).toHaveText('E2E Hot');

  await page.goto('/admin/catalog/badges', { waitUntil: 'domcontentloaded' });
  const rule = page.locator('[data-badge-rule="e2e-hot"]');
  await rule.locator('form[data-confirm] button[type="submit"]').click();
  await expect(page.locator('[data-admin-confirm] [data-confirm-accept]')).toBeVisible(); // confirmation dialog, nothing deleted yet
  await expect(rule).toBeVisible();
  await page.locator('[data-admin-confirm] [data-confirm-accept]').click();
  await expect(page.locator('[data-badge-rule="e2e-hot"]')).toHaveCount(0);
});

test('delivery countries and regions can be activated selectively', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/shipments/countries', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  expect(await page.locator('[data-shipping-countries] input[name="countries[]"]').count()).toBeGreaterThan(200);

  // Keep Ukraine (the market country) and add Denmark, so parallel storefront tests are unaffected.
  await page.locator('input[name="countries[]"][value="UA"]').check();
  await page.locator('input[name="countries[]"][value="DK"]').check();
  await page.locator('[data-shipping-countries] form button[type="submit"]').click();
  await page.locator('[data-admin-confirm] [data-confirm-accept]').click();
  await expect(page.locator('[data-shipping-open]')).toHaveCount(0);

  await page.goto('/admin/shipments/countries?country=UA', { waitUntil: 'domcontentloaded' });
  await page.locator('[data-shipping-regions] button[name="action"][value="import"]').click();
  await expect(page.locator('[data-shipping-regions] tbody tr')).toHaveCount(26);
  await page.locator('[data-shipping-regions] input[name="enabled[]"]').first().uncheck();
  await page.locator('[data-shipping-regions] form:has(input[value="save"]) button[type="submit"]').last().click();
  await expect(page.locator('[data-shipping-regions] input[name="enabled[]"]').first()).not.toBeChecked();
});

test('rich text editor has an HTML source mode', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  const edit = page.locator('a[href^="/admin/catalog/products/"][href$="/edit"]').first();
  await edit.click();
  await page.waitForLoadState('domcontentloaded');
  const toggle = page.locator('.rich-editor__source-toggle').first();
  await expect(toggle).toBeVisible();
  await toggle.click();
  const source = page.locator('.rich-editor__source').first();
  await expect(source).toBeVisible();
  await source.fill('<p>HTML <strong>mode</strong></p><table><tbody><tr><td>x</td></tr></tbody></table>');
  await toggle.click();
  await expect(page.locator('.rich-editor__content strong').first()).toHaveText('mode');
  await expect(page.locator('.rich-editor__content table').first()).toBeVisible();
  await expect(page.locator('textarea[name="description"]').first()).toHaveValue(/<strong>mode<\/strong>/);
});
