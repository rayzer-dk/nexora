import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/|$)/), page.locator('button[type="submit"]').click()]);
}

async function setLocale(page: Page, code: string, enabled: boolean): Promise<void> {
  await page.goto('/admin/system/localization', { waitUntil: 'domcontentloaded' });
  const box = page.locator(`input[name="locale[${code}][enabled]"]`);
  if (await box.count()) await (enabled ? box.check() : box.uncheck());
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form[action$="/localization/locales"] button[type="submit"]').click()]);
}

async function setAi(page: Page, enabled: boolean): Promise<void> {
  await page.goto('/admin/system/ai', { waitUntil: 'domcontentloaded' });
  const form = page.locator('form:has(input[name="api_key"])').first();
  await form.locator('input[name="enabled"]').setChecked(enabled);
  if (enabled) await form.locator('input[name="api_key"]').fill('sk-test-0123456789abcdef');
  await Promise.all([page.waitForURL(/system\/ai/), form.locator('button[type="submit"]').click()]);
}

test('translate every language with one click, one field with its own button, then save all', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings run once.');
  await loginAdmin(page);
  // The assistant itself needs the internet: the browser gets a canned answer, so the click flow is what is tested.
  await page.route('**/admin/api/ai/task', async (route) => {
    const body = new URLSearchParams(route.request().postData() || '');
    await route.fulfill({ json: { ok: true, fields: { text: `TR-${body.get('fields[target]')}-${(body.get('fields[text]') || '').slice(0, 12)}` } } });
  });
  try {
    await setLocale(page, 'en-US', true);
    await setLocale(page, 'de-DE', true);
    await setAi(page, true);

    await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
    const href = await page.locator('a[href*="/admin/catalog/products/"][href$="/edit"]').first().getAttribute('href');
    const id = href!.split('/')[4];

    // the product card offers the same one-click action
    await page.goto(href!, { waitUntil: 'domcontentloaded' });
    await expect(page.locator(`a[href$="/admin/catalog/products/${id}/translations?auto=1"]`)).toBeVisible();

    await page.goto(`/admin/catalog/products/${id}/translations`, { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    const name = (locale: string) => page.locator(`form[action$="/translations/${locale}"] input[name="name"]`);
    await page.locator('[data-translate-all]').click();
    await expect(page.locator('[data-save-all]')).toBeVisible({ timeout: 20_000 });
    await expect(name('en-US')).toHaveValue(/^TR-en-US-/);
    await expect(name('de-DE')).toHaveValue(/^TR-de-DE-/);

    // translating changes the form only; saving is an in-place request and the page is never reloaded
    await page.evaluate(() => { (window as unknown as { __kept: boolean }).__kept = true; });
    await page.locator('[data-save-all]').click();
    await expect(page.locator('[data-bulk-status]')).toContainText('збережено', { timeout: 15_000 });
    expect(await page.evaluate(() => (window as unknown as { __kept?: boolean }).__kept)).toBe(true);
    await page.reload({ waitUntil: 'load' });
    await expectNoServerError(page);
    await expect(name('en-US')).toHaveValue(/^TR-en-US-/);
    await expect(name('de-DE')).toHaveValue(/^TR-de-DE-/);

    // one field on its own stays available
    const openTab = (locale: string) => page.locator(`[data-lang-item="${locale}"] button, [data-lang-item="${locale}"] a`).first().click();
    await openTab('en-US');
    await name('en-US').fill('typed by hand');
    await page.locator('form[action$="/translations/en-US"] label:has(input[name="name"]) [data-translate-one]').click();
    await expect(name('en-US')).toHaveValue(/^TR-en-US-/);
    await expect(name('de-DE')).toHaveValue(/^TR-de-DE-/);

    // a single language saves in place too
    await page.evaluate(() => { (window as unknown as { __kept: boolean }).__kept = true; });
    await page.locator('form[action$="/translations/en-US"] button[type="submit"]').click();
    await expect(page.locator('form[action$="/translations/en-US"] [data-ai-status]')).not.toHaveText('', { timeout: 15_000 });
    expect(await page.evaluate(() => (window as unknown as { __kept?: boolean }).__kept)).toBe(true);

    // and the link from the product card starts the whole thing by itself
    page.on('dialog', (dialog) => dialog.accept()); // unsaved edits trigger the leave-page warning
    await page.goto(`/admin/catalog/products/${id}/translations?auto=1`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-save-all]')).toBeVisible({ timeout: 20_000 });
    await expect(name('de-DE')).toHaveValue(/^TR-de-DE-/);
    await expect(name('en-US')).toHaveValue(/^TR-en-US-/);
  } finally {
    await setAi(page, false);
    await setLocale(page, 'de-DE', false);
    await setLocale(page, 'en-US', false);
  }
});
