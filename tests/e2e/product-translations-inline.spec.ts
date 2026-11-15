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
  const tabs = block.locator('.lang-form-bar .lang-tabs__tab');
  const count = await tabs.count();
  expect(count, 'content language plus one tab for every other language').toBeGreaterThanOrEqual(2);
  const name = (code: string | null) => block.locator(`[data-lang-panel="${code}"] input[name$="[name]"]`).first();

  const last = tabs.nth(count - 1);
  const code = await last.getAttribute('data-lang-tab');
  await last.click();
  await expect(name(code)).toBeVisible();
  // name and description decide the mark of a language; only those of the chosen language are on screen
  const required = block.locator('label[data-lang-panel][data-lang-required]:visible');
  await expect(required).toHaveCount(2);
  for (const label of await required.all()) await expect(label).toHaveAttribute('data-lang-panel', code!);

  const field = name(code);
  const before = await field.inputValue();
  const typed = `Переклад ${Date.now() % 100000}`;
  await field.fill(typed);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('.admin-save-bar button[data-primary-submit]').first().click()]);
  await expectNoServerError(page);

  await page.goto(edit!, { waitUntil: 'domcontentloaded' });
  await block.locator(`.lang-form-bar .lang-tabs__tab[data-lang-tab="${code}"]`).click();
  await expect(name(code)).toHaveValue(typed);

  // restore the earlier text so that other specs see the demo data they expect
  if (before !== '') {
    await name(code).fill(before);
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('.admin-save-bar button[data-primary-submit]').first().click()]);
  }
});

test('a category takes its other languages in the same form; "copy from" fills the language on screen', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/catalog/categories', { waitUntil: 'domcontentloaded' });
  const edit = await page.locator('main a[href^="/admin/catalog/categories/"][href$="/edit"]').first().getAttribute('href');
  await page.goto(edit!, { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);

  const bar = page.locator('form[data-lang-scope] .lang-form-bar');
  const tabs = bar.locator('.lang-tabs__tab');
  expect(await tabs.count()).toBeGreaterThanOrEqual(2);
  await expect(bar.locator('.lang-tabs__item').first().locator('.lang-tabs__default')).toBeVisible();
  await expect(bar.locator('[data-lang-actions]')).toBeHidden();

  const code = await tabs.last().getAttribute('data-lang-tab');
  const locale = code!.replace(/^lang-/, '');
  await tabs.last().click();
  await expect(bar.locator('[data-lang-actions]')).toBeVisible();
  await expect(page.locator('input[name="name"]')).toBeHidden();
  const h1 = page.locator(`input[name="tr[${locale}][h1]"]`);
  const before = await h1.inputValue();

  // the main H1 is copied into the language on screen
  await tabs.first().click();
  const mainH1 = await page.locator('input[name="h1"]').inputValue();
  await tabs.last().click();
  await h1.fill('');
  page.once('dialog', (dialog) => dialog.accept());
  await bar.locator('[data-lang-copy]').click();
  if (mainH1 !== '') await expect(h1).toHaveValue(mainH1);

  const typed = `H1 ${locale} ${Date.now() % 100000}`;
  await h1.fill(typed);
  await Promise.all([page.waitForURL(/\/admin\/catalog\/categories/), page.locator('form[data-lang-scope] button[type="submit"].is-primary').click()]);
  await expectNoServerError(page);
  await page.goto(edit!, { waitUntil: 'domcontentloaded' });
  await tabs.last().click();
  await expect(h1).toHaveValue(typed);

  await h1.fill(before);
  await Promise.all([page.waitForURL(/\/admin\/catalog\/categories/), page.locator('form[data-lang-scope] button[type="submit"].is-primary').click()]);
});

test('the product list shows which languages are translated and sorts by it', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/catalog/products?sort=translations&dir=asc', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const cells = page.locator('a.admin-translations');
  test.skip((await cells.count()) === 0, 'The store has one language.');
  const total = await page.locator('.admin-translations').first().locator('.admin-translations__lang').count();
  expect(total).toBeGreaterThanOrEqual(2);
  const done = async () => (await cells.locator('small').allTextContents()).map((text) => Number(text.split('/')[0]));
  const ascending = await done();
  expect(ascending).toEqual([...ascending].sort((a, b) => a - b));
  await expect(page.locator('th[data-sort-key="translations"]')).toHaveAttribute('aria-sort', 'ascending');

  await page.goto('/admin/catalog/products?sort=translations&dir=desc', { waitUntil: 'domcontentloaded' });
  const descending = await done();
  expect(descending).toEqual([...descending].sort((a, b) => b - a));
});
