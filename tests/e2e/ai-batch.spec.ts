import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

// No AI provider is ever called here: the daily AI limit is set to 0, so every round of the batch stops before a request
// and the batch waits in status "limit". The translation itself (one request per entity and language, the glossary, saving
// as a real translation) is covered by tests/Unit/AiBatchServiceTest.php with a stub model.

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
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

async function setDailyLimit(page: Page, limit: number): Promise<void> {
  await page.goto('/admin/system/ai', { waitUntil: 'domcontentloaded' });
  const form = page.locator('form:has(input[name="daily_limit"])');
  await form.locator('input[name="daily_limit"]').fill(String(limit));
  await Promise.all([page.waitForURL(/system\/ai/), form.locator('button[type="submit"]').click()]);
}

async function cancelActiveBatches(page: Page): Promise<void> {
  await page.goto('/admin/catalog/ai-batch', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 10; i += 1) {
    const cancel = page.locator('form[action$="/cancel"] button[type="submit"]').first();
    if (!(await cancel.count())) return;
    await cancel.click();
    await Promise.all([page.waitForURL(/ai-batch$/), page.locator('[data-confirm-accept]').click()]);
  }
}

test('batch translation: queue per language, limit pause without any AI request, pause, resume, cancel', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings run once.');
  await loginAdmin(page);
  let aiRequests = 0;
  page.on('request', (request) => { if (/api\.openai\.com|googleapis\.com|anthropic\.com/.test(request.url())) aiRequests += 1; });
  try {
    await setLocale(page, 'de-DE', true);
    await setAi(page, true);
    await setDailyLimit(page, 0);
    await cancelActiveBatches(page);

    // the page is in the catalogue menu
    await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
    await page.locator('.admin-sidebar a[href="/admin/catalog/ai-batch"]').click();
    await expect(page).toHaveURL(/\/admin\/catalog\/ai-batch$/);
    await expectNoServerError(page);

    // "translate everything again" asks first; saying no keeps the page
    const form = page.locator('[data-ai-batch-form]');
    await form.locator('input[name="mode"][value="overwrite"]').check();
    await form.locator('[data-ai-batch-start]').click();
    await expect(page.locator('[data-confirm-message]')).toBeVisible();
    await page.locator('.admin-modal__actions [data-confirm-cancel]').click();
    await expect(page).toHaveURL(/ai-batch$/);

    // only untranslated products, German only
    await form.locator('input[name="mode"][value="missing"]').check();
    for (const box of await form.locator('input[name="locales[]"]').all()) await box.setChecked((await box.getAttribute('value')) === 'de-DE');
    await Promise.all([page.waitForURL(/ai-batch$/), form.locator('[data-ai-batch-start]').click()]);
    await expect(page.locator('.admin-notice.is-success')).toBeVisible();
    const batch = page.locator('[data-ai-batch][data-status="running"]').first();
    const row = batch.locator('[data-locale-row="de-DE"]');
    await expect(row).toBeVisible();
    const pending = Number(await row.locator('[data-n="pending"]').textContent());
    expect(pending).toBeGreaterThan(0);

    // the same start again queues nothing twice
    const again = page.locator('[data-ai-batch-form]');
    for (const box of await again.locator('input[name="locales[]"]').all()) await box.setChecked((await box.getAttribute('value')) === 'de-DE');
    await Promise.all([page.waitForURL(/ai-batch$/), again.locator('[data-ai-batch-start]').click()]);
    await expect(page.locator('.admin-notice.is-error')).toBeVisible();
    expect(Number(await page.locator('[data-ai-batch][data-status="running"]').first().locator('[data-locale-row="de-DE"] [data-n="pending"]').textContent())).toBe(pending);

    // "process now" with the limit used up: nothing is sent, the batch waits for tomorrow
    await page.locator('[data-ai-batch-run]').click(); // the page reloads itself when the batch status changes
    const limited = page.locator('[data-ai-batch][data-status="limit"]').first();
    await expect(limited).toBeVisible({ timeout: 20_000 });
    await expect(limited.locator('[data-ai-batch-state]')).not.toHaveText('');
    await expect(limited.locator('[data-locale-row="de-DE"] [data-n="done"]')).toHaveText('0');
    await expect(limited.locator('[data-locale-row="de-DE"] [data-n="failed"]')).toHaveText('0');
    expect(Number(await limited.locator('[data-locale-row="de-DE"] [data-n="pending"]').textContent())).toBe(pending);

    // pause, resume, cancel
    await Promise.all([page.waitForURL(/ai-batch$/), limited.locator('form[action$="/pause"] button').click()]);
    await expect(page.locator('[data-ai-batch][data-status="paused"]')).toHaveCount(1);
    await Promise.all([page.waitForURL(/ai-batch$/), page.locator('[data-ai-batch][data-status="paused"] form[action$="/resume"] button').click()]);
    await expect(page.locator('[data-ai-batch][data-status="running"]')).toHaveCount(1);
    await page.locator('[data-ai-batch][data-status="running"] form[action$="/cancel"] button').click();
    await Promise.all([page.waitForURL(/ai-batch$/), page.locator('[data-confirm-accept]').click()]);
    const cancelled = page.locator('[data-ai-batch][data-status="cancelled"]').first();
    await expect(cancelled).toBeVisible();
    await expect(cancelled.locator('[data-locale-row="de-DE"] [data-n="pending"]')).toHaveText('0');

    // the cron page lists the background task
    await page.goto('/admin/system/cron', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-task-code="ai_batch"]')).toHaveCount(1);

    // without an AI provider the page says so and links to the settings
    await setAi(page, false);
    await page.goto('/admin/catalog/ai-batch', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-ai-batch-empty="provider"] a[href="/admin/system/ai"]')).toBeVisible();
    expect(aiRequests).toBe(0);
  } finally {
    await cancelActiveBatches(page);
    await setDailyLimit(page, 200);
    await setAi(page, false);
    await setLocale(page, 'de-DE', false);
  }
});
