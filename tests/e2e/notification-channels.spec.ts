import { createServer, type Server } from 'node:net';
import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/|$)/), page.locator('button[type="submit"]').click()]);
}

/** A tiny SMTP server that records every message it is given. */
function smtpSink(): Promise<{ server: Server; port: number; mails: string[] }> {
  const mails: string[] = [];
  const server = createServer((socket) => {
    let data = false;
    let buffer = '';
    let mail = '';
    socket.write('220 sink ESMTP\r\n');
    socket.on('data', (chunk) => {
      buffer += chunk.toString();
      let at: number;
      while ((at = buffer.indexOf('\r\n')) >= 0) {
        const line = buffer.slice(0, at);
        buffer = buffer.slice(at + 2);
        if (data) {
          if (line === '.') {
            data = false;
            mails.push(mail);
            mail = '';
            socket.write('250 queued\r\n');
          } else {
            mail += line + '\n';
          }
          continue;
        }
        const cmd = line.slice(0, 4).toUpperCase();
        if (cmd === 'EHLO' || cmd === 'HELO') socket.write('250 sink\r\n');
        else if (cmd === 'DATA') {
          data = true;
          socket.write('354 go\r\n');
        } else if (cmd === 'QUIT') socket.end('221 bye\r\n');
        else socket.write('250 ok\r\n');
      }
    });
  });
  return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve({ server, port: (server.address() as { port: number }).port, mails })));
}

test('the admin sets the SMTP server and a test e-mail really arrives; a wrong server shows the real error', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings run once.');
  const sink = await smtpSink();
  await loginAdmin(page);
  const form = () => page.locator('form:has(input[name="smtp_enabled"])');
  const save = async (host: string, port: number, enabled: boolean) => {
    await page.goto('/admin/commerce/notification-channels', { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    await form().locator('input[name="smtp_enabled"]').setChecked(enabled);
    await form().locator('input[name="smtp_host"]').fill(host);
    await form().locator('input[name="smtp_port"]').fill(String(port));
    await form().locator('select[name="smtp_encryption"]').selectOption('none');
    await form().locator('input[name="from_address"]').fill('shop@e2e.test');
    await Promise.all([page.waitForURL(/notification-channels/), form().locator('button[type="submit"]').click()]);
  };
  const sendTest = async () => {
    const mailForm = page.locator('form[action$="/notification-channels/test"]:has(input[name="channel"][value="email"])');
    await mailForm.locator('input[name="to"]').fill('owner@e2e.test');
    await Promise.all([page.waitForURL(/notification-channels/), mailForm.locator('button').click()]);
  };
  try {
    await save('127.0.0.1', sink.port, true);
    await sendTest();
    await expect(page.locator('.admin-notice.is-success').first()).toBeAttached();
    await expect.poll(() => sink.mails.length, { timeout: 10_000 }).toBe(1);
    expect(sink.mails[0]).toContain('To: owner@e2e.test');
    expect(sink.mails[0]).toMatch(/From: .*shop@e2e\.test/);
    // The order e-mails use the same Twig template, so a rendered HTML part proves they work too.
    expect(sink.mails[0]).toContain('text/html');

    await save('127.0.0.1', 1, true);
    await sendTest();
    await expect(page.locator('.admin-notice.is-error').first()).toBeAttached();
    expect(sink.mails.length).toBe(1);
  } finally {
    await save('', 587, false);
    sink.server.close();
  }
});

test('the order-alert Telegram bot is set in the admin and its test message reaches the chat', async ({ page, request }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings run once.');
  const mock = process.env.E2E_TELEGRAM_MOCK || 'http://127.0.0.1:8099';
  const up = await request.get(`${mock}/__log`).then((r) => r.ok()).catch(() => false);
  test.skip(!up, 'The Telegram stand-in is not running.');
  await request.post(`${mock}/__reset`);
  await loginAdmin(page);
  await page.goto('/admin/commerce/notification-channels', { waitUntil: 'domcontentloaded' });
  const form = page.locator('form:has(input[name="smtp_enabled"])');
  try {
    await form.locator('input[name="tg_enabled"]').check();
    await form.locator('input[name="tg_token"]').fill('123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
    await form.locator('input[name="tg_chat_id"]').fill('-1001234567891');
    await Promise.all([page.waitForURL(/notification-channels/), form.locator('button[type="submit"]').click()]);
    await Promise.all([page.waitForURL(/notification-channels/), page.locator('form:has(input[name="channel"][value="telegram"]) button').click()]);
    await expect(page.locator('.admin-notice.is-success').first()).toBeAttached();
    const calls: Array<{ method: string; payload: Record<string, unknown> }> = await (await request.get(`${mock}/__log`)).json();
    const sent = calls.find((c) => c.method === 'sendMessage');
    expect(sent?.payload.chat_id).toBe('-1001234567891');
  } finally {
    await page.goto('/admin/commerce/notification-channels', { waitUntil: 'domcontentloaded' });
    await form.locator('input[name="tg_enabled"]').uncheck();
    await Promise.all([page.waitForURL(/notification-channels/), form.locator('button[type="submit"]').click()]);
  }
});
