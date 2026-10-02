import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError, openProductTab } from './helpers';

// Release 3.31.0: product options (colour, memory) with a price difference; the storefront follows the chosen combination.

async function login(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')),
    page.locator('button[type="submit"]').click(),
  ]);
}

async function submitOptions(page: Page, action: string): Promise<void> {
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith('/options')),
    page.locator(`button[name="_option_action"][value="${action}"]`).first().click(),
  ]);
  await page.waitForLoadState('domcontentloaded');
  await expectNoServerError(page);
}

// The three browser projects share one store. Generating variants binds the main variant to the first combination only
// while it has no option values yet, so every run needs a published, in-stock product that no earlier run has touched.
async function openProductWithoutOptions(page: Page): Promise<void> {
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  const hrefs = await page
    .locator('a[href*="/admin/catalog/products/"][href$="/edit"]')
    .evaluateAll((nodes) => nodes.map((node) => node.getAttribute('href') ?? ''));
  for (const href of [...new Set(hrefs)].filter(Boolean)) {
    await page.goto(href, { waitUntil: 'domcontentloaded' });
    const status = await page.locator('select[name="status"]').inputValue();
    const stock = Number((await page.locator('input[name="stock_quantity"]').inputValue()).replace(',', '.'));
    await openProductTab(page, 'sales');
    const hasOptions = (await page.locator('#options .admin-option').count()) > 0;
    if (status === 'published' && stock > 0 && !hasOptions) return;
  }
  throw new Error('No published, in-stock product without options is available for this test.');
}

test('options create variants and the storefront price follows the chosen value', async ({ page }) => {
  await login(page);
  await openProductWithoutOptions(page);
  const slug = await page.locator('input[name="slug"]').inputValue();
  // The three browser projects share one store and this product: unique labels keep every run independent of earlier ones.
  const stamp = Date.now().toString(36);
  const red = `Red ${stamp}`;
  const blackLabel = `Black ${stamp}`;

  await openProductTab(page, 'sales');
  if (!(await page.locator('#options input[name="new_option[name]"]').isVisible())) await page.locator('#options .admin-inline-create summary').click();
  await page.locator('#options input[name="new_option[name]"]').fill(`Colour ${stamp}`);
  await page.locator('#options input[name="new_option[values]"]').fill(`${red}, ${blackLabel}`);
  await submitOptions(page, 'save');
  await openProductTab(page, 'sales');
  const black = page.locator('#options .admin-option__row').filter({ has: page.locator(`input[value="${blackLabel}"]`) });
  await black.locator('input[name$="[delta]"]').fill('50.00');
  await submitOptions(page, 'save');
  await openProductTab(page, 'sales');
  await submitOptions(page, 'generate');
  await expect(page.locator('.admin-notice.is-success').first()).toBeAttached();

  await page.goto('/' + slug.replace(/^\/+/, ''), { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const picker = page.locator('[data-option-picker]');
  await expect(picker).toBeVisible();
  const price = page.locator('.product-price__current').first();
  const before = await price.textContent();
  const variantInput = page.locator('input[name="variant_id"]').first();
  const variantBefore = await variantInput.inputValue();
  await picker.locator('[data-option-value]').filter({ hasText: blackLabel }).click();
  await expect(variantInput).not.toHaveValue(variantBefore);
  await expect(price).not.toHaveText(before ?? '');

  // A new combination has no stock yet, so it cannot be bought; the main one can, and its value is shown in the cart.
  await expect(page.locator('form[data-buy-actions] button[type="submit"]').first()).toBeDisabled();
  await picker.locator('[data-option-value]').filter({ hasText: red }).click();
  const added = page.waitForResponse((r) => r.request().method() === 'POST' && /\/cart/.test(new URL(r.url()).pathname));
  await page.locator('form[data-buy-actions] button[type="submit"]').first().click();
  expect((await added).status()).toBeLessThan(400);
  await page.goto('/cart', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('main')).toContainText(red);
});
