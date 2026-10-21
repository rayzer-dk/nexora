import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError, loginAdmin } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

// No AI provider is ever called here. Part 1 queues the bulk action with the daily limit at 0 and cancels the batch. Part 2 walks the
// "for review" page (apply, undo, reject) over jobs that were prepared by a stub model through `commerce:ai:batch` on the test copy
// (E2E_REVIEW_SEEDED=1); the same flow with real database rows is covered by tests/Unit/AiBatchServiceTest.php.

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

test('bulk AI actions: the selection goes to the AI page, nothing is sent, overwrite asks first', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings run once.');
  await loginAdmin(page);
  let aiRequests = 0;
  page.on('request', (request) => { if (/api\.openai\.com|googleapis\.com|anthropic\.com/.test(request.url())) aiRequests += 1; });
  await setAi(page, true);
  await setDailyLimit(page, 0);

  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  const select = page.locator('select[name="action"]').first();
  for (const value of ['ai_descriptions', 'ai_seo', 'ai_translate']) await expect(select.locator(`option[value="${value}"]`)).toHaveCount(1);
  const checks = page.locator('input[data-row-check]');
  await checks.nth(0).check();
  await checks.nth(1).check();
  await select.selectOption('ai_seo');
  await page.locator('[data-bulk-submit]').click();
  await Promise.all([page.waitForURL(/ai-batch\?selection=1/), page.locator('[data-confirm-accept]').click()]);
  await expectNoServerError(page);
  const panel = page.locator('[data-ai-selection]');
  await expect(panel).toBeVisible();
  await expect(panel).toContainText('2');

  // all fields, even filled ones: asks first; "no" keeps the page
  await panel.locator('button[name="mode"][value="overwrite"]').click();
  await expect(page.locator('[data-confirm-message]')).toBeVisible();
  await page.locator('.admin-modal__actions [data-confirm-cancel]').click();
  await expect(page).toHaveURL(/ai-batch\?selection=1/);

  // yes: the jobs are queued as a batch of writing tasks with a "for review" column; cancelling leaves the products as they are
  await panel.locator('button[name="mode"][value="overwrite"]').click();
  await Promise.all([page.waitForURL(/ai-batch$/), page.locator('[data-confirm-accept]').click()]);
  const batch = page.locator('[data-ai-batch][data-status="running"]').first();
  await expect(batch.locator('[data-n="review"]').first()).toHaveText('0');
  expect(Number(await batch.locator('[data-n="pending"]').first().textContent())).toBe(2);
  await batch.locator('form[action$="/cancel"] button').click();
  await Promise.all([page.waitForURL(/ai-batch$/), page.locator('[data-confirm-accept]').click()]);
  await expect(page.locator('[data-ai-batch][data-status="cancelled"]').first()).toBeVisible();
  expect(aiRequests).toBe(0);
  await setAi(page, false);
});

test('review page: apply one, undo, reject all', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop' || process.env.E2E_REVIEW_SEEDED !== '1', 'Needs texts prepared by the stub model.');
  await loginAdmin(page);
  await page.goto('/admin/catalog/ai-batch/review', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const items = page.locator('[data-ai-review-item]');
  expect(await items.count()).toBeGreaterThanOrEqual(2);
  const total = await items.count();
  const first = items.first();
  await expect(first.locator('[data-ai-after]').first()).not.toHaveText('');

  await Promise.all([page.waitForLoadState('domcontentloaded'), first.locator('[data-ai-apply]').click()]);
  await expect(items).toHaveCount(total - 1);
  const remaining = total - 1;
  await expect(page.locator('[data-undo-form]')).toBeVisible();
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('[data-undo-form] button').click()]);
  await page.goto('/admin/catalog/ai-batch/review', { waitUntil: 'domcontentloaded' });
  expect(await items.count()).toBe(remaining + 1);

  await page.locator('form[action$="/review/reject"][data-confirm] button').click();
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('[data-confirm-accept]').click()]);
  await expect(page.locator('[data-ai-review-empty]')).toBeVisible();
});
