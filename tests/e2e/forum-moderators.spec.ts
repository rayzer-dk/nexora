import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

test('a topic moderator appointed in the admin can hide a message in that topic only', async ({ browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating forum flow runs once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  const suffix = `${Date.now()}-${testInfo.retry}`;
  const email = `e2e-mod-${suffix}@example.test`;
  const password = 'Nexora-Customer-2026!';
  const reply = `Team reply ${suffix}`;

  const adminCtx = await browser.newContext();
  const admin = await adminCtx.newPage();
  await admin.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await admin.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await admin.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([admin.waitForURL(/\/admin(?:\/(?!login)|$)/), admin.locator('button[type="submit"]').click()]);

  // A published topic with one reply from the team.
  await admin.goto('/admin/forum', { waitUntil: 'domcontentloaded' });
  await admin.locator('.admin-tabs .admin-tab').nth(3).click();
  await admin.locator('details:has(form[action="/admin/forum/new-topic"]) > summary').click();
  const create = admin.locator('form[action="/admin/forum/new-topic"]');
  await create.locator('input[name="title"]').fill(`Moderated topic ${suffix}`);
  await create.locator('textarea[name="body"]').fill('Opening message by the team');
  await Promise.all([admin.waitForURL(/\/admin\/forum\/topic\/\d+/), create.locator('button[type="submit"]').click()]);
  const adminTopicUrl = admin.url();
  const publicPath = await admin.locator('a[href^="/forum/t/"]').first().getAttribute('href');
  await admin.locator('form[action$="/reply"] textarea[name="body"]').fill(reply);
  await Promise.all([admin.waitForLoadState('domcontentloaded'), admin.locator('form[action$="/reply"] button[type="submit"]').click()]);

  // A member signs up and confirms the e-mail.
  const memberCtx = await browser.newContext();
  const member = await memberCtx.newPage();
  await member.goto('/account/register', { waitUntil: 'domcontentloaded' });
  const registerForm = member.locator('form.account-form');
  await registerForm.locator('input[name="display_name"]').fill(`Moderator ${suffix}`);
  await registerForm.locator('input[name="email"]').fill(email);
  await registerForm.locator('input[name="password"]').fill(password);
  await Promise.all([member.waitForURL(/\/account\/login(?:\?|$)/), registerForm.locator('button[type="submit"]').click()]);
  await member.locator('input[name="_username"]').fill(email);
  await member.locator('input[name="_password"]').fill(password);
  await Promise.all([member.waitForURL(/\/account(?:\?.*)?$/), member.locator('form.account-form button[type="submit"]').click()]);
  await member.goto('/account/verification', { waitUntil: 'domcontentloaded' });
  const requestForm = member.locator('form[action="/account/verification/request"]');
  if (await requestForm.count()) {
    await requestForm.locator('input[name="channel"][value="email"]').check().catch(() => {});
    await requestForm.locator('button[type="submit"]').click();
    await member.waitForLoadState('domcontentloaded');
  }
  const code = execFileSync('php', ['tests/e2e/read-verification-code.php', email], { encoding: 'utf8', env: process.env }).trim();
  const confirmForm = member.locator('form[action="/account/verification/confirm"]');
  await confirmForm.locator('select[name="channel"]').selectOption('email');
  await confirmForm.locator('input[name="code"]').fill(code);
  await Promise.all([member.waitForURL(/\/account\/verification/), confirmForm.locator('button[type="submit"]').click()]);

  // Before the appointment there are no moderation buttons.
  await member.goto(publicPath!, { waitUntil: 'domcontentloaded' });
  await expect(member.locator('.fx-post', { hasText: reply })).toBeVisible();
  await expect(member.locator('form[action*="/moderate/hide"]')).toHaveCount(0);

  const memberId = execFileSync('php', ['tests/e2e/read-customer-id.php', email], { encoding: 'utf8', env: process.env }).trim();
  await admin.goto(adminTopicUrl, { waitUntil: 'domcontentloaded' });
  await admin.locator('form[action$="/moderators/add"] input[name="who"]').fill(memberId);
  await Promise.all([admin.waitForLoadState('domcontentloaded'), admin.locator('form[action$="/moderators/add"] button[type="submit"]').click()]);
  await expect(admin.getByText(`ID ${memberId}`)).toBeVisible();

  await member.goto(publicPath!, { waitUntil: 'domcontentloaded' });
  const post = member.locator('.fx-post', { hasText: reply });
  // Hiding asks for an optional reason in a small pop-over.
  await post.locator('details.fx-pop:has(form[action*="/moderate/hide"]) > summary').click();
  await post.locator('form[action*="/moderate/hide"] input[name="reason"]').fill('Off topic');
  await Promise.all([member.waitForLoadState('domcontentloaded'), post.locator('form[action*="/moderate/hide"] button').click()]);
  await expect(member.locator('.fx-post:not(.is-hidden)', { hasText: reply })).toHaveCount(0);

  await adminCtx.close();
  await memberCtx.close();
});
