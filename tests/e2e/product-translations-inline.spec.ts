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

  const block = page.locator('section[data-lang-scope]').first();
  await expect(block).toBeVisible();
  const tabs = block.locator('.lang-tabs__tab');
  const count = await tabs.count();
  expect(count, 'one tab for every other language').toBeGreaterThanOrEqual(1);
  const panels = block.locator('[data-lang-panel]');
  await expect(panels.locator(':scope:visible')).toHaveCount(1);

  const last = tabs.nth(count - 1);
  const code = await last.getAttribute('data-lang-tab');
  await last.click();
  const panel = block.locator(`[data-lang-panel="${code}"]`);
  await expect(panel).toBeVisible();
  await expect(panels.locator(':scope:visible')).toHaveCount(1);

  const name = panel.locator('input[name$="[name]"]');
  const before = await name.inputValue();
  const typed = `Переклад ${Date.now() % 100000}`;
  await name.fill(typed);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('.admin-save-bar button[data-primary-submit]').first().click()]);
  await expectNoServerError(page);

  await page.goto(edit!, { waitUntil: 'domcontentloaded' });
  await block.locator(`.lang-tabs__tab[data-lang-tab="${code}"]`).click();
  await expect(block.locator(`[data-lang-panel="${code}"] input[name$="[name]"]`)).toHaveValue(typed);

  // restore the earlier text so that other specs see the demo data they expect
  if (before !== '') {
    await block.locator(`[data-lang-panel="${code}"] input[name$="[name]"]`).fill(before);
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('.admin-save-bar button[data-primary-submit]').first().click()]);
  }
});
