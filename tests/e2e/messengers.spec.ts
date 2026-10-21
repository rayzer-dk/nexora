import { createHmac } from 'node:crypto';
import { expect, test, type Page } from '@playwright/test';
import { loginAdmin } from './helpers';

// Release 3.97.24: customers follow an order in Telegram or Viber through a one-time link token.
// Needs the stand-in on :8099 and TELEGRAM_API_BASE pointing at it.

const MOCK = process.env.E2E_TELEGRAM_MOCK || 'http://127.0.0.1:8099';
const TOKEN = '987654321:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

type MockMessageCall = { method: string; payload: { url: string; chat_id: number; text: string } };

async function saveField(page: Page, provider: string, values: Record<string, string>): Promise<void> {
  await page.goto('/admin/system/integrations/connections/messengers', { waitUntil: 'domcontentloaded' });
  const section = page.locator(`#conn-${provider}`);
  await section.locator('summary').click();
  for (const [name, value] of Object.entries(values)) {
    const field = section.locator(`[name="key[${name}]"]`);
    if ((await field.evaluate((e) => e.tagName)) === 'SELECT') await field.selectOption(value);
    else await field.fill(value);
  }
  await Promise.all([page.waitForURL(/connections\/messengers/), section.locator('button[type="submit"]').click()]);
}

test('a customer links Telegram to an order with a one-time link and stops it again', async ({ page, request }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared settings once.');
  test.setTimeout(240_000);
  const up = await request.get(`${MOCK}/__log`).then((r) => r.ok()).catch(() => false);
  test.skip(!up, 'The Telegram stand-in is not running.');
  await request.post(`${MOCK}/__reset`);
  await loginAdmin(page);

  try {
    await saveField(page, 'telegram_customers', { CUSTOMER_TELEGRAM_ENABLED: '1', CUSTOMER_TELEGRAM_BOT_TOKEN: TOKEN, CUSTOMER_TELEGRAM_BOT_USERNAME: 'e2e_shop_bot' });
    expect(await page.content()).not.toContain(TOKEN);

    // the webhook is registered at the address of this shop, with a secret part
    await Promise.all([page.waitForURL(/connections\/messengers/), page.locator('form[action$="/messengers/webhooks"] button').click()]);
    const calls: MockMessageCall[] = await (await request.get(`${MOCK}/__log`)).json();
    const hook = calls.find((c) => c.method === 'setWebhook');
    expect(hook?.payload.url).toMatch(/\/webhooks\/messengers\/telegram\/[a-f0-9]{32}$/);
    const hookPath = new URL(hook!.payload.url).pathname;

    // an order, then the link from its page opens the bot with a token
    await page.goto('/admin/orders/manual', { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="name"]').fill('Messenger Клієнт');
    await page.locator('input[name="phone"]').fill('+380501112277');
    await page.locator('input[name="sku[]"]').first().fill('DEMO-078');
    await page.locator('input[name="qty[]"]').first().fill('1');
    await page.locator('input[name="delivery"]').fill('Київ, відділення 3');
    await Promise.all([page.waitForURL(/\/admin\/orders\/[0-9a-f-]{36}/), page.locator('main form button[type="submit"]').last().click()]);
    const orderId = page.url().match(/orders\/([0-9a-f-]{36})/)![1];

    const first = await request.get(`/checkout/success/${orderId}/messenger/telegram`, { maxRedirects: 0 });
    expect(first.status()).toBe(302);
    const link = first.headers()['location'];
    expect(link).toMatch(/^https:\/\/t\.me\/e2e_shop_bot\?start=[a-f0-9]{24}$/);
    const second = await request.get(`/checkout/success/${orderId}/messenger/telegram`, { maxRedirects: 0 });
    expect(second.headers()['location']).toBe(link); // one token per order and channel
    const token = link.split('start=')[1];
    expect((await request.get(`/checkout/success/${orderId}/messenger/viber`, { maxRedirects: 0 })).status()).toBe(404); // not set up

    // a wrong secret is refused; the right one links the chat and the bot answers
    expect((await request.post('/webhooks/messengers/telegram/' + '0'.repeat(32), { data: {} })).status()).toBe(404);
    await request.post('http://127.0.0.1:8099/__reset');
    const update = (text: string) => ({ data: { message: { text, chat: { id: 424242 }, from: { first_name: 'Ірина' } } } });
    expect((await request.post(hookPath, update('/start ' + 'f'.repeat(24)))).ok()).toBe(true);
    expect((await (await request.get(`${MOCK}/__log`)).json()).filter((c: MockMessageCall) => c.method === 'sendMessage')).toHaveLength(0); // unknown token
    expect((await request.post(hookPath, update('/start ' + token))).ok()).toBe(true);
    const greeting = (await (await request.get(`${MOCK}/__log`)).json()).find((c: MockMessageCall) => c.method === 'sendMessage');
    expect(String(greeting.payload.chat_id)).toBe('424242');
    expect(greeting.payload.text).toBeTruthy();

    // stopping switches the chat off
    await request.post(hookPath, update('/stop'));
    const log = await (await request.get(`${MOCK}/__log`)).json();
    expect(log.filter((c: MockMessageCall) => c.method === 'sendMessage')).toHaveLength(2);
  } finally {
    await saveField(page, 'telegram_customers', { CUSTOMER_TELEGRAM_ENABLED: '0' });
  }
});

test('Viber events must carry a valid signature', async ({ page, request }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared settings once.');
  test.setTimeout(120_000);
  await loginAdmin(page);
  const viberToken = 'e2e-viber-auth-token-0123456789';
  try {
    await saveField(page, 'viber_customers', { CUSTOMER_VIBER_ENABLED: '1', CUSTOMER_VIBER_AUTH_TOKEN: viberToken, CUSTOMER_VIBER_NAME: 'E2E Shop', CUSTOMER_VIBER_URI: 'e2eshop' });
    const body = JSON.stringify({ event: 'conversation_started', user: { id: 'viber-user-1', name: 'Олег' }, context: 'a'.repeat(24) });
    expect((await request.post('/webhooks/messengers/viber', { data: body, headers: { 'Content-Type': 'application/json' } })).status()).toBe(401);
    expect((await request.post('/webhooks/messengers/viber', { data: body, headers: { 'Content-Type': 'application/json', 'X-Viber-Content-Signature': 'bad' } })).status()).toBe(401);
    const sig = createHmac('sha256', viberToken).update(body).digest('hex');
    const ok = await request.post('/webhooks/messengers/viber', { data: body, headers: { 'Content-Type': 'application/json', 'X-Viber-Content-Signature': sig } });
    expect(ok.status()).toBe(200); // unknown token: accepted, nobody linked
    expect((await ok.json()).status).toBe(0);
  } finally {
    await saveField(page, 'viber_customers', { CUSTOMER_VIBER_ENABLED: '0' });
  }
});
