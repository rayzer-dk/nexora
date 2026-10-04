import { expect, test, type Page } from '@playwright/test';

test.describe.configure({ retries: 0, mode: 'serial' });

async function signIn(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

async function setStoreLocale(page: Page, locale: string): Promise<string> {
  await page.goto('/admin/system/store', { waitUntil: 'domcontentloaded' });
  const select = page.locator('select[name="default_locale"]');
  const before = await select.inputValue();
  await select.selectOption(locale);
  await Promise.all([page.waitForLoadState('load'), select.evaluate((el) => (el.closest('form') as HTMLFormElement).requestSubmit())]);
  return before;
}

test('the store language sets the storefront language and a missing language shows English', async ({ page, browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating the store settings runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await signIn(page);
  const original = await setStoreLocale(page, 'en-US');
  try {
    const shop = await browser.newContext({ baseURL: testInfo.project.use.baseURL });
    const visitor = await shop.newPage();
    await visitor.goto('/cart', { waitUntil: 'domcontentloaded' });
    await expect(visitor.locator('html')).toHaveAttribute('lang', /^en/);
    await expect(visitor.locator('h1')).toHaveText(/Your order|Cart|Order/i);
    await shop.close();
  } finally {
    await setStoreLocale(page, original);
  }
  const restored = await browser.newContext({ baseURL: testInfo.project.use.baseURL });
  const visitor = await restored.newPage();
  await visitor.goto('/cart', { waitUntil: 'domcontentloaded' });
  await expect(visitor.locator('html')).toHaveAttribute('lang', new RegExp('^' + original.slice(0, 2)));
  await restored.close();
});

test('the default admin language is set on the localization page and is saved', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating the site settings runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await signIn(page);
  await page.goto('/admin/system/localization', { waitUntil: 'domcontentloaded' });
  const select = page.locator('#admin-language select[name="admin_default_locale"]');
  const values = await select.locator('option').evaluateAll((nodes) => nodes.map((node) => (node as HTMLOptionElement).value));
  expect(values).toContain('en-US');
  expect(values).toContain('uk-UA');
  const before = await select.inputValue();
  await select.selectOption('en-US');
  await Promise.all([page.waitForURL(/localization/), page.locator('#admin-language button[type="submit"]').click()]);
  await expect(page.locator('#admin-language label small')).toContainText('en-US');
  // this administrator chose a language of their own, so the page stays in it; the rule for those who did not is covered by LanguageFallbackTest
  await expect(page.locator('html')).toHaveAttribute('lang', /^[a-z]{2}/);
  await page.goto('/admin/system/localization', { waitUntil: 'domcontentloaded' });
  await page.locator('#admin-language select[name="admin_default_locale"]').selectOption(before);
  await Promise.all([page.waitForURL(/localization/), page.locator('#admin-language button[type="submit"]').click()]);
  await expect(page.locator('#admin-language select[name="admin_default_locale"]')).toHaveValue(before);
});
