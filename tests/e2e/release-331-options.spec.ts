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

test('options create variants and the storefront price follows the chosen value', async ({ page }) => {
  await login(page);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  const editHref = await page.locator('a[href*="/admin/catalog/products/"][href$="/edit"]').first().getAttribute('href');
  await page.goto(editHref!, { waitUntil: 'domcontentloaded' });
  const slug = await page.locator('input[name="slug"]').inputValue();

  await openProductTab(page, 'sales');
  if (!(await page.locator('#options input[name="new_option[name]"]').isVisible())) await page.locator('#options .admin-inline-create summary').click();
  await page.locator('#options input[name="new_option[name]"]').fill('Colour');
  await page.locator('#options input[name="new_option[values]"]').fill('Red, Black');
  await submitOptions(page, 'save');
  await openProductTab(page, 'sales');
  const black = page.locator('#options .admin-option__row').filter({ has: page.locator('input[value="Black"]') });
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
  await picker.locator('[data-option-value]').filter({ hasText: 'Black' }).click();
  await expect(variantInput).not.toHaveValue(variantBefore);
  await expect(price).not.toHaveText(before ?? '');

  // A new combination has no stock yet, so it cannot be bought; the main one can, and its value is shown in the cart.
  await expect(page.locator('form[data-buy-actions] button[type="submit"]').first()).toBeDisabled();
  await picker.locator('[data-option-value]').filter({ hasText: 'Red' }).click();
  const added = page.waitForResponse((r) => r.request().method() === 'POST' && /\/cart/.test(new URL(r.url()).pathname));
  await page.locator('form[data-buy-actions] button[type="submit"]').first().click();
  expect((await added).status()).toBeLessThan(400);
  await page.goto('/cart', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('main')).toContainText('Red');
});
