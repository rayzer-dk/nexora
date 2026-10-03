import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

// Needs the Telegram stand-in: `php -S 127.0.0.1:8099 tests/e2e/fixtures/telegram-mock.php` and TELEGRAM_API_BASE pointing at it.
const MOCK = process.env.E2E_TELEGRAM_MOCK || 'http://127.0.0.1:8099';
const TOKEN = '123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
const GROUP = '-1001234567890';

test.describe.configure({ retries: 0, mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/|$)/), page.locator('button[type="submit"]').click()]);
}

async function mockLog(page: Page): Promise<Array<{ method: string; payload: Record<string, unknown> }>> {
  return (await page.request.get(`${MOCK}/__log`)).json();
}

test('website visitors chat with the staff through one Telegram topic each', async ({ page, browser, request }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings run once.');
  const mockUp = await request.get(`${MOCK}/__log`).then((r) => r.ok()).catch(() => false);
  test.skip(!mockUp, 'The Telegram stand-in is not running.');
  await request.post(`${MOCK}/__reset`);
  await loginAdmin(page);

  // The contact widget is the home of the chat button.
  await page.goto('/admin/appearance/contact-widget', { waitUntil: 'domcontentloaded' });
  const widget = page.locator('form[data-contact-widget-form]');
  await widget.locator('input[name="enabled"]').check();
  await widget.locator('input[name="callback_enabled"]').check();
  await Promise.all([page.waitForURL(/contact-widget/), widget.locator('button[type="submit"]').click()]);

  await page.goto('/admin/appearance/support-chat', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const settingsForm = () => page.locator('form[action$="/admin/appearance/support-chat"]').first();
  await settingsForm().locator('input[name="enabled"]').check();
  await settingsForm().locator('input[name="site_chat_enabled"]').check();
  await settingsForm().locator('input[name="bot_token"]').fill(TOKEN);
  await settingsForm().locator('input[name="group_chat_id"]').fill(GROUP);
  await Promise.all([page.waitForURL(/support-chat/), settingsForm().locator('button[type="submit"]').click()]);
  await expect(page.locator('.admin-notice.is-success').first()).toBeAttached();
  const hook = (await page.locator('.admin-card-note code').first().textContent()) || '';
  const secret = hook.trim().split('/').pop() || '';
  expect(secret).toMatch(/^[a-f0-9]{48}$/);

  // "Check" talks to the stand-in: the webhook is not connected yet, so it must say so.
  await page.locator('form[action$="/support-chat/check"] button').click();
  await expect(page.locator('.admin-notice.is-error').first()).toBeAttached();

  const ctx = await browser.newContext({ baseURL: testInfo.project.use.baseURL, locale: 'uk-UA' });
  const shop = await ctx.newPage();
  try {
    await shop.goto('/', { waitUntil: 'domcontentloaded' });
    await shop.locator('.cw__toggle').click();
    await shop.locator('[data-cw-support]').click();
    const dialog = shop.locator('[data-support-chat]');
    await expect(dialog).toBeVisible();
    await shop.waitForTimeout(1500); // the spam guard rejects a form that is sent faster than a person can fill it
    await dialog.locator('input[name="name"]').fill('E2E Visitor');
    await dialog.locator('textarea[name="message"]').fill('Do you ship to Lviv?');
    await dialog.locator('.support-chat__send').click();
    await expect(dialog.locator('.support-chat__msg.is-mine')).toContainText('Do you ship to Lviv?');

    const calls = await mockLog(page);
    const topic = calls.find((c) => c.method === 'createForumTopic');
    expect(topic?.payload.chat_id).toBe(GROUP);
    const forwarded = calls.filter((c) => c.method === 'sendMessage').find((c) => c.payload.text === 'Do you ship to Lviv?');
    expect(forwarded).toBeTruthy();
    const topicId = Number(forwarded?.payload.message_thread_id);
    expect(topicId).toBeGreaterThan(0);

    // Telegram update ids are stored for de-duplication, so a re-run against the same database needs fresh ones.
    const base = Math.floor(Date.now() / 1000);
    // A staff member answers inside that topic; a message in some other topic must not reach this visitor.
    const update = (id: number, thread: number, text: string) => ({
      update_id: id,
      message: { message_id: id, is_topic_message: true, message_thread_id: thread, chat: { id: Number(GROUP), type: 'supergroup', is_forum: true, title: 'E2E staff' }, from: { id: 42, is_bot: false }, text },
    });
    const post = (body: unknown, token = secret) => request.post(`/support/telegram/webhook/${secret}`, { data: body, headers: { 'X-Telegram-Bot-Api-Secret-Token': token } });
    expect((await post(update(base + 1, topicId + 50, 'for someone else'))).ok()).toBeTruthy();
    expect((await post(update(base + 2, topicId, 'Yes, we ship to Lviv.'))).ok()).toBeTruthy();
    expect((await post(update(base + 3, topicId, 'wrong secret'), 'nope')).status()).toBe(404);

    await expect(dialog.locator('.support-chat__msg.is-staff')).toHaveCount(1, { timeout: 15_000 });
    await expect(dialog.locator('.support-chat__msg.is-staff')).toContainText('Yes, we ship to Lviv.');
  } finally {
    await ctx.close();
    await page.goto('/admin/appearance/support-chat', { waitUntil: 'domcontentloaded' });
    await settingsForm().locator('input[name="enabled"]').uncheck();
    await Promise.all([page.waitForURL(/support-chat/), settingsForm().locator('button[type="submit"]').click()]);
    // The widget and its callback form are shared storefront state: leave them off for the specs that follow.
    await page.goto('/admin/appearance/contact-widget', { waitUntil: 'domcontentloaded' });
    await widget.locator('input[name="callback_enabled"]').uncheck();
    await widget.locator('input[name="enabled"]').uncheck();
    await Promise.all([page.waitForURL(/contact-widget/), widget.locator('button[type="submit"]').click()]);
  }
});
