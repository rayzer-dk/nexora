import { createServer, type Server } from 'node:net';
import { expect, test, type Locator, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/|$)/), page.locator('button[type="submit"]').click()]);
}

/** Admin forms of these pages are sent in place: wait for the request to finish (the page itself does not navigate). */
async function inPlace(page: Page, button: Locator): Promise<void> {
  await Promise.all([
    page.waitForResponse((r) => r.request().resourceType() === 'fetch' && r.request().method() === 'GET' && r.url().includes('/admin/')),
    button.click(),
  ]);
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
    await inPlace(page, form().locator('button[type="submit"]'));
  };
  const sendTest = async () => {
    const mailForm = page.locator('form[action$="/notification-channels/test"]:has(input[name="channel"][value="email"])');
    await mailForm.locator('input[name="to"]').fill('owner@e2e.test');
    await inPlace(page, mailForm.locator('button'));
  };
  try {
    await save('127.0.0.1', sink.port, true);
    await sendTest();
    await expect(page.locator('.admin-toast.is-success').first()).toBeVisible();
    await expect.poll(() => sink.mails.length, { timeout: 10_000 }).toBe(1);
    expect(sink.mails[0]).toContain('To: owner@e2e.test');
    expect(sink.mails[0]).toMatch(/From: .*shop@e2e\.test/);
    // The order e-mails use the same Twig template, so a rendered HTML part proves they work too.
    expect(sink.mails[0]).toContain('text/html');

    await save('127.0.0.1', 1, true);
    await sendTest();
    await expect(page.locator('.admin-toast.is-error').first()).toBeVisible();
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
    await inPlace(page, form.locator('button[type="submit"]'));
    await inPlace(page, page.locator('form:has(input[name="channel"][value="telegram"]) button'));
    await expect(page.locator('.admin-toast.is-success').first()).toBeVisible();
    const calls: Array<{ method: string; payload: Record<string, unknown> }> = await (await request.get(`${mock}/__log`)).json();
    const sent = calls.find((c) => c.method === 'sendMessage');
    expect(sent?.payload.chat_id).toBe('-1001234567891');
  } finally {
    await page.goto('/admin/commerce/notification-channels', { waitUntil: 'domcontentloaded' });
    await form.locator('input[name="tg_enabled"]').uncheck();
    await inPlace(page, form.locator('button[type="submit"]'));
  }
});

test('e-mail colours are chosen in the admin and reach the rendered e-mails; reset restores the built-in look', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings run once.');
  await loginAdmin(page);
  const preview = async () => (await page.request.get('/admin/commerce/email-preview/render/order_created')).text();
  const designForm = () => page.locator('form[action$="/notification-channels/design"]');
  try {
    await page.goto('/admin/commerce/notification-channels', { waitUntil: 'domcontentloaded' });
    await designForm().locator('input[name="header_bg"]').fill('#7c2d12');
    await designForm().locator('input[name="accent"]').fill('#15803d');
    await designForm().locator('input[name="footer"]').fill('E2E footer line');
    await inPlace(page, designForm().locator('button[type="submit"]:not([name="reset"])'));
    const html = await preview();
    expect(html).toContain('background:#7c2d12');
    expect(html).toContain('color:#15803d');
    expect(html).toContain('background:#15803d;color:#ffffff'); // the "view order" button follows the accent colour
    expect(html).toContain('E2E footer line');
  } finally {
    await page.goto('/admin/commerce/notification-channels', { waitUntil: 'domcontentloaded' });
    await inPlace(page, designForm().locator('button[name="reset"]'));
  }
  const html = await preview();
  expect(html).toContain('background:#0b63f6');
  expect(html).not.toContain('E2E footer line');
});

test("a campaign can carry the shop's own HTML (cleaned, sent through the real template) and the customer and subscriber lists export", async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings run once.');
  const sink = await smtpSink();
  await loginAdmin(page);
  const channels = page.locator('form:has(input[name="smtp_enabled"])');
  const setSmtp = async (enabled: boolean) => {
    await page.goto('/admin/commerce/notification-channels', { waitUntil: 'domcontentloaded' });
    await channels.locator('input[name="smtp_enabled"]').setChecked(enabled);
    await channels.locator('input[name="smtp_host"]').fill(enabled ? '127.0.0.1' : '');
    await channels.locator('input[name="smtp_port"]').fill(String(enabled ? sink.port : 587));
    await channels.locator('select[name="smtp_encryption"]').selectOption('none');
    await inPlace(page, channels.locator('button[type="submit"]'));
  };
  const sendTest = async (format: string, body: string) => {
    await page.goto('/admin/commerce/campaigns', { waitUntil: 'domcontentloaded' });
    const form = page.locator('form:has(select[name="format"])');
    await form.locator('select[name="format"]').selectOption(format);
    await form.locator('input[name="subject"]').fill('E2E campaign');
    await form.locator('textarea[name="body"]').fill(body);
    await form.locator('input[name="test_to"]').fill('reader@e2e.test');
    await inPlace(page, form.locator('button[formaction$="/campaigns/test"]'));
  };
  const own = '<table role="presentation" width="100%" style="background:#112233"><tr><td style="padding:20px;color:#ffffff;font-size:18px">Own layout <a href="https://example.com/x">link</a><script>alert(1)</script><img src="http://insecure.test/a.png" onerror="x()"></td></tr></table>';
  try {
    await setSmtp(true);

    await sendTest('html', own);
    await expect.poll(() => sink.mails.length, { timeout: 10_000 }).toBe(1);
    const inTemplate = sink.mails[0];
    expect(inTemplate).toContain('Own layout');
    expect(inTemplate).not.toContain('<script');
    expect(inTemplate).not.toContain('onerror');
    expect(inTemplate).toContain('background:#0b63f6'); // the shop template's header is still there

    await sendTest('html_raw', own);
    await expect.poll(() => sink.mails.length, { timeout: 10_000 }).toBe(2);
    const raw = sink.mails[1];
    expect(raw).toContain('Own layout');
    expect(raw).not.toContain('<script');
    expect(raw).not.toContain('background:#0b63f6'); // no shop header around a full custom layout
  } finally {
    await setSmtp(false);
    sink.server.close();
  }

  for (const [path, header] of [['/admin/commerce/customers/export.csv', 'id,name,email'], ['/admin/commerce/subscribers/export.csv', 'email,language,status']] as const) {
    const response = await page.request.get(path);
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('text/csv');
    expect((await response.text()).replace(/^\uFEFF/, '')).toMatch(new RegExp(`^${header}`));
  }
});

test('every e-mail template fits a phone and a desktop screen without sideways scrolling', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Read-only, runs once.');
  await loginAdmin(page);
  for (const width of [320, 390, 1280]) {
    await page.setViewportSize({ width, height: 900 });
    for (const template of ['order_created', 'order_status', 'generic', 'campaign']) {
      await page.goto(`/admin/commerce/email-preview/render/${template}`, { waitUntil: 'load' });
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      expect(overflow, `${template} at ${width}px`).toBeLessThanOrEqual(0);
    }
  }
});
