import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { expectNoServerError } from './helpers';

test('the shop team writes, hides and deletes forum content, bans members and sets auto-publishing', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating forum flow runs once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  const suffix = `${Date.now()}-${testInfo.retry}`;
  const email = `e2e-staff-${suffix}@example.test`;
  const password = 'Nexora-Customer-2026!';
  const title = `Team topic ${suffix}`;
  const reply = `Instant reply ${suffix}`;

  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);

  const setMode = async (mode: string) => {
    await page.goto('/admin/forum', { waitUntil: 'domcontentloaded' });
    await page.locator('.admin-tabs .admin-tab').nth(7).click();
    const form = page.locator('form[action="/admin/forum/settings"]');
    await form.locator('select[name="replies_mode"]').selectOption(mode);
    await Promise.all([page.waitForLoadState('domcontentloaded'), form.locator('button[type="submit"]').click()]);
    await expectNoServerError(page);
  };

  try {
    await setMode('authorized');

    await page.goto('/admin/forum', { waitUntil: 'domcontentloaded' });
    await page.locator('.admin-tabs .admin-tab').nth(3).click();
    await page.locator('details:has(form[action="/admin/forum/new-topic"]) > summary').click();
    const create = page.locator('form[action="/admin/forum/new-topic"]');
    await create.locator('input[name="title"]').fill(title);
    await create.locator('textarea[name="body"]').fill('Written by the shop team');
    await Promise.all([page.waitForURL(/\/admin\/forum\/topic\/\d+/), create.locator('button[type="submit"]').click()]);
    const adminTopicUrl = page.url();
    const publicPath = await page.locator('a[href^="/forum/t/"]').first().getAttribute('href');
    await expect(page.locator('h1')).toContainText(title);

    // A member registers, confirms the e-mail and answers: the reply is public at once.
    await page.goto('/account/register', { waitUntil: 'domcontentloaded' });
    const registerForm = page.locator('form.account-form');
    await registerForm.locator('input[name="display_name"]').fill(`Staff Member ${suffix}`);
    await registerForm.locator('input[name="email"]').fill(email);
    await registerForm.locator('input[name="password"]').fill(password);
    await Promise.all([page.waitForURL(/\/account\/login(?:\?|$)/), registerForm.locator('button[type="submit"]').click()]);
    await page.locator('input[name="_username"]').fill(email);
    await page.locator('input[name="_password"]').fill(password);
    await Promise.all([page.waitForURL(/\/account(?:\?.*)?$/), page.locator('form.account-form button[type="submit"]').click()]);
    await page.goto('/account/verification', { waitUntil: 'domcontentloaded' });
    const requestForm = page.locator('form[action="/account/verification/request"]');
    if (await requestForm.count()) {
      await requestForm.locator('input[name="channel"][value="email"]').check().catch(() => {});
      await requestForm.locator('button[type="submit"]').click();
      await page.waitForLoadState('domcontentloaded');
    }
    const code = execFileSync('php', ['tests/e2e/read-verification-code.php', email], { encoding: 'utf8', env: process.env }).trim();
    const confirmForm = page.locator('form[action="/account/verification/confirm"]');
    await confirmForm.locator('select[name="channel"]').selectOption('email');
    await confirmForm.locator('input[name="code"]').fill(code);
    await Promise.all([page.waitForURL(/\/account\/verification/), confirmForm.locator('button[type="submit"]').click()]);

    await page.goto(publicPath!, { waitUntil: 'domcontentloaded' });
    const topicUrl = page.url();
    await page.locator('[data-forum-compose-open]').click();
    const dialog = page.locator('[data-forum-compose]');
    await expect(dialog).toBeVisible();
    await dialog.locator('textarea[name="body"]').fill(reply);
    await page.waitForTimeout(1500); // the anti-spam guard rejects forms sent less than a second after they were shown
    const consent = page.locator('[data-commerce-consent] button').last();
    if (await consent.isVisible()) await consent.click();
    await Promise.all([page.waitForLoadState('domcontentloaded'), dialog.locator('button[type="submit"]').last().click()]);
    await expect(page.locator('.fx-post', { hasText: reply })).toBeVisible();

    // The team hides the reply: it leaves the public page, then is restored and deleted for good.
    await page.goto(adminTopicUrl, { waitUntil: 'domcontentloaded' });
    const row = page.locator('table.admin-table tbody tr').filter({ hasText: reply });
    await Promise.all([page.waitForLoadState('domcontentloaded'), row.locator('form[action$="/hide"] button').click()]);
    await page.goto(topicUrl, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.fx-post', { hasText: reply })).toHaveCount(0);

    await page.goto(adminTopicUrl, { waitUntil: 'domcontentloaded' });
    const hidden = page.locator('table.admin-table tbody tr').filter({ hasText: reply });

    // Ban the author from the same row.
    await hidden.locator('details.admin-collapse summary').click();
    await hidden.locator('input[name="reason"]').fill('E2E ban');
    await hidden.locator('form[action="/admin/forum/bans"] button[type="submit"]').click();
    await page.locator('[data-confirm-accept]').click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.admin-notice.is-success, [data-toast-source]').first()).toBeAttached();

    await page.goto(adminTopicUrl, { waitUntil: 'domcontentloaded' });
    await hidden.locator('form[action$="/delete"] button').click();
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('[data-confirm-accept]').click()]);
    await expect(page.locator('table.admin-table tbody tr').filter({ hasText: reply })).toHaveCount(0);
  } finally {
    await setMode('moderate');
  }
});
