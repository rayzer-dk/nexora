import { expect, test } from '@playwright/test';
import { expectNoServerError, loginAdmin } from './helpers';

test.beforeEach(({}, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Edits one product, runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
});

test('another language of a product is typed in the product form and saved with it', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  const edit = await page.locator('a[href^="/admin/catalog/products/"][href$="/edit"]').first().getAttribute('href');
  await page.goto(edit!, { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);

  const block = page.locator('form[data-lang-scope]').first();
  await expect(block).toBeVisible();
  const tabs = block.locator('.product-lang-bar .lang-tabs__tab');
  const count = await tabs.count();
  expect(count, 'content language plus one tab for every other language').toBeGreaterThanOrEqual(2);
  const name = (code: string | null) => block.locator(`[data-lang-panel="${code}"] input[name$="[name]"]`).first();

  const last = tabs.nth(count - 1);
  const code = await last.getAttribute('data-lang-tab');
  await last.click();
  await expect(name(code)).toBeVisible();
  await expect(block.locator('label[data-lang-panel][data-lang-required]:visible')).toHaveCount(1);

  const field = name(code);
  const before = await field.inputValue();
  const typed = `Переклад ${Date.now() % 100000}`;
  await field.fill(typed);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('.admin-save-bar button[data-primary-submit]').first().click()]);
  await expectNoServerError(page);

  await page.goto(edit!, { waitUntil: 'domcontentloaded' });
  await block.locator(`.product-lang-bar .lang-tabs__tab[data-lang-tab="${code}"]`).click();
  await expect(name(code)).toHaveValue(typed);

  // restore the earlier text so that other specs see the demo data they expect
  if (before !== '') {
    await name(code).fill(before);
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('.admin-save-bar button[data-primary-submit]').first().click()]);
  }
});
