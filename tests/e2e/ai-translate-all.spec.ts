import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError, loginAdmin } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

// The canned answer of the assistant: "TR-<target>-<start of the text>" for one text, or for every text of a language at once.
function cannedTranslation(postData: string, cut: number): Record<string, unknown> {
  const body = new URLSearchParams(postData);
  const target = body.get('fields[target]');
  if (body.get('task') === 'translate_fields') {
    const texts = JSON.parse(body.get('fields[json]') || '{}') as Record<string, string>;
    return { ok: true, fields: Object.fromEntries(Object.entries(texts).map(([key, text]) => [key, `TR-${target}-${text.slice(0, cut)}`])) };
  }
  return { ok: true, fields: { text: `TR-${target}-${(body.get('fields[text]') || '').slice(0, cut)}` } };
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
  const tasks: string[] = [];
  await page.route('**/admin/api/ai/task', async (route) => {
    tasks.push(new URLSearchParams(route.request().postData() || '').get('task') || '');
    await route.fulfill({ json: cannedTranslation(route.request().postData() || '', 12) });
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
    // an existing translation is replaced only after a yes: "no" fills the empty fields and keeps the rest
    await name('en-US').evaluate((input: HTMLInputElement) => { input.value = 'kept by hand'; });
    page.once('dialog', (dialog) => dialog.dismiss());
    await page.locator('[data-translate-all]').click();
    await expect(name('de-DE')).toHaveValue(/^TR-de-DE-/, { timeout: 20_000 });
    await expect(name('en-US')).toHaveValue('kept by hand');
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('[data-translate-all]').click();
    await expect(page.locator('[data-save-all]')).toBeVisible({ timeout: 20_000 });
    await expect(name('en-US')).toHaveValue(/^TR-en-US-/);
    await expect(name('de-DE')).toHaveValue(/^TR-de-DE-/);
    // every language went out as one request with all of its fields
    expect(tasks.slice(-2)).toEqual(['translate_fields', 'translate_fields']);

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
    // en-US is on from the installation (Ukrainian, English and Russian ship complete), so it stays on.
  }
});

test('forms and navigation labels translate with one click and are saved only with the form', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings run once.');
  await loginAdmin(page);
  await page.route('**/admin/api/ai/task', async (route) => {
    await route.fulfill({ json: cannedTranslation(route.request().postData() || '', 10) });
  });
  try {
    await setLocale(page, 'en-US', true);
    await setAi(page, true);

    // navigation: the labels of the other languages come from the main one
    await page.goto('/admin/appearance/navigation', { waitUntil: 'load' });
    await page.locator('form[action$="/navigation/save"] input[name="label[uk-UA]"]').fill('Головна');
    await page.evaluate(() => { (window as unknown as { __kept: boolean }).__kept = true; });
    page.once('dialog', (dialog) => dialog.accept()); // labels that already exist are replaced after a yes
    await page.locator('[data-translate-all]').click();
    await expect(page.locator('form[action$="/navigation/save"] input[name="label[en-US]"]')).toHaveValue(/^TR-en-US-/, { timeout: 15_000 });
    expect(await page.evaluate(() => (window as unknown as { __kept?: boolean }).__kept)).toBe(true);

    // forms: one button per language fills every text of that language; nothing is stored before the form is saved
    await page.goto('/admin/content/forms/new', { waitUntil: 'load' });
    const editor = page.locator('form[data-form-editor]');
    await editor.locator('input[name="name"]').fill(`E2E translate form ${Date.now()}`);
    await editor.locator('input[name="submit_label"]').fill('Send request');
    await editor.locator('input[name="fields[0][label]"]').fill('Your name');
    await Promise.all([page.waitForURL(/\/admin\/content\/forms\/\d+/), editor.locator('button[type="submit"]').first().click()]);
    const panel = page.locator('[data-form-translation="en-US"]');
    await panel.locator('[data-translate-from]').click();
    const submitLabel = panel.locator('input[name$="[submit_label]"]');
    await expect(submitLabel).toHaveValue(/^TR-en-US-/, { timeout: 15_000 });
    await expect(panel.locator('input[name$="[label]"]').first()).toHaveValue(/^TR-en-US-/);
    await page.reload({ waitUntil: 'load' });
    await expect(page.locator('[data-form-translation="en-US"] input[name$="[submit_label]"]')).toHaveValue(''); // not saved yet
    await panel.locator('[data-translate-from]').click();
    await expect(page.locator('[data-form-translation="en-US"] input[name$="[submit_label]"]')).toHaveValue(/^TR-en-US-/, { timeout: 15_000 });
    await Promise.all([page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/admin/content/forms/save')), page.locator('form[data-form-editor] button[type="submit"]').first().click()]);
    await page.waitForLoadState('load'); // the save redirects; reloading before it lands aborts the navigation
    await page.goto(page.url(), { waitUntil: 'load' });
    await expect(page.locator('[data-form-translation="en-US"] input[name$="[submit_label]"]')).toHaveValue(/^TR-en-US-/); // saved with the form
  } finally {
    await setAi(page, false);
  }
});
