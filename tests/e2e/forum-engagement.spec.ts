import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { expectNoServerError } from './helpers';

test('forum votes, favorites and the formatting toolbar work for a signed-in member', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating forum flow runs once per CI database.');

  const suffix = `${Date.now()}-${testInfo.retry}`;
  const email = `e2e-forum-${suffix}@example.test`;
  const password = 'Nexora-Customer-2026!';

  await page.goto('/account/register', { waitUntil: 'domcontentloaded' });
  const registerForm = page.locator('form.account-form');
  await registerForm.locator('input[name="display_name"]').fill(`Forum Member ${suffix}`);
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

  await page.goto('/forum', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('.fx-board').first()).toBeVisible();

  await page.goto('/forum/t/1/welcome-demo', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const post = page.locator('.fx-post').first();
  const score = post.locator('.fx-vote__score');
  const before = Number(((await score.innerText()).replace('+', '')).trim());
  await Promise.all([page.waitForLoadState('domcontentloaded'), post.locator('.fx-vote__btn[aria-label]').first().click()]);
  await expectNoServerError(page);
  const after = Number(((await page.locator('.fx-post').first().locator('.fx-vote__score').innerText()).replace('+', '')).trim());
  expect(after).toBe(before + 1);

  const favorite = page.locator('.fx-head__actions form button').first();
  await expect(favorite).toHaveAttribute('aria-pressed', 'false');
  await Promise.all([page.waitForLoadState('domcontentloaded'), favorite.click()]);
  await expect(page.locator('.fx-head__actions form button').first()).toHaveAttribute('aria-pressed', 'true');

  await page.locator('[data-forum-compose-open]').click();
  const dialog = page.locator('[data-forum-compose]');
  await expect(dialog).toBeVisible();
  const textarea = dialog.locator('textarea[name="body"]');
  await textarea.fill('hello');
  await textarea.evaluate((el: HTMLTextAreaElement) => { el.focus(); el.setSelectionRange(0, 5); });
  await dialog.locator('.fx-toolbar button').first().click();
  await expect(textarea).toHaveValue(/\*\*hello\*\*/);

  // Avatar upload re-encodes the picture and shows it on the profile.
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mP8z8BQz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC', 'base64');
  await page.goto('/forum/profile', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="avatar"]').setInputFiles({ name: 'me.png', mimeType: 'image/png', buffer: png });
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form.account-form button[type="submit"]').click()]);
  await expectNoServerError(page);
  await expect(page.locator('.fx-avatar-edit img.fx-avatar')).toHaveAttribute('src', /\/media\/forum\/avatars\/.+\.webp$/);

  // A new topic can carry a picture and a poll (it still waits for moderation).
  await page.goto('/forum/general', { waitUntil: 'domcontentloaded' });
  await page.locator('[data-forum-compose-open]').first().click();
  const topicForm = page.locator('form[action="/forum/general/topics"]');
  await expect(topicForm.locator('input[name="title"]')).toBeVisible();
  await topicForm.locator('input[name="title"]').fill(`Poll topic ${suffix}`);
  await topicForm.locator('textarea[name="body"]').fill('Which one do you prefer? @someone');
  await topicForm.locator('input[name="images[]"]').setInputFiles({ name: 'pic.png', mimeType: 'image/png', buffer: png });
  await topicForm.locator('.fx-pollform summary').click();
  await topicForm.locator('input[name="poll_question"]').fill('Best colour?');
  await topicForm.locator('textarea[name="poll_options"]').fill('Red\nBlue');
  const consent = page.locator('[data-commerce-consent] button').last();
  if (await consent.isVisible()) await consent.click();
  await Promise.all([page.waitForLoadState('domcontentloaded'), topicForm.locator('button[type="submit"]').click()]);
  await expectNoServerError(page);
  await expect(page.locator('.store-notice.is-success, [data-store-toast-source]').first()).toBeAttached();
});
