import { createHash } from 'node:crypto';
import { expect, test, type Page } from '@playwright/test';

/**
 * Optional: runs only against a server started with LIQPAY_ENABLED=1, LIQPAY_PUBLIC_KEY and
 * LIQPAY_PRIVATE_KEY, and E2E_LIQPAY_PRIVATE_KEY set to the same private key.
 */
const PRIVATE_KEY = process.env.E2E_LIQPAY_PRIVATE_KEY ?? '';

test.describe.configure({ retries: 0, mode: 'serial' });

// eslint-disable-next-line no-empty-pattern
test.beforeEach(async ({}, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop' || PRIVATE_KEY === '', 'Needs a LiqPay-enabled server.');
});

const sign = (data: string): string =>
  createHash('sha1')
    .update(PRIVATE_KEY + data + PRIVATE_KEY)
    .digest('base64');

async function placeLiqPayOrder(page: Page): Promise<{ payload: Record<string, string>; returnPath: string }> {
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const href = await page.locator('[data-product-card] h2 a').first().getAttribute('href');
  await page.goto(href!, { waitUntil: 'domcontentloaded' });
  const add = page.waitForResponse((r) => r.url().endsWith('/cart/add') && r.request().method() === 'POST');
  await page.locator('form[data-buy-actions] [data-primary-buy]').click();
  expect((await add).status()).toBeLessThan(500);
  await page.goto('/checkout', { waitUntil: 'domcontentloaded' });
  await page.locator('[data-checkout-form] [name="name"]').fill('E2E LiqPay');
  await page.locator('[data-checkout-form] [name="phone"]').fill('+380501234567');
  const mail = page.locator('[data-checkout-form] [name="email"]');
  if (await mail.count()) await mail.fill(`liqpay-${Date.now()}@example.test`);
  const city = page.locator('[data-delivery-city]');
  if (await city.count()) {
    await city.fill('Київ');
    const manual = page.locator('[name="delivery_manual"]');
    if (await manual.count()) {
      await manual.evaluate((el) => {
        const input = el as HTMLInputElement;
        input.value = 'Київ, тестове відділення 1';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
      });
    }
  }
  const region = page.locator('select[name="delivery_region"]');
  if (await region.count()) await region.selectOption({ index: 1 });
  await page.locator('input[name="payment_method"][value="liqpay"]').check();

  let checkoutUrl = '';
  // Redirected navigations are not reliably interceptable; observe the request and never let it leave the sandbox.
  page.on('request', (req) => {
    if (req.url().startsWith('https://www.liqpay.ua/') && req.url().includes('signature=')) checkoutUrl = req.url();
  });
  await page.route(/^https:\/\/www\.liqpay\.ua\//, (route) => route.abort());
  await page.locator('button.place-order').click();
  await expect.poll(() => checkoutUrl, { timeout: 20_000 }).not.toBe('');
  const query = new URL(checkoutUrl).searchParams;
  expect(query.get('signature')).toBe(sign(query.get('data')!));
  const payload = JSON.parse(Buffer.from(query.get('data')!, 'base64').toString('utf8'));
  return { payload, returnPath: new URL(payload.result_url).pathname };
}

test('a signed LiqPay callback marks the order paid exactly once; forged or replayed ones do nothing', async ({
  page,
  request,
}) => {
  const { payload, returnPath } = await placeLiqPayOrder(page);
  expect(payload.action).toBe('pay');
  expect(payload.server_url).toMatch(/\/webhooks\/payments\/liqpay$/);

  await page.goto(returnPath, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('h1')).toContainText('Перевіряємо');

  const data = Buffer.from(
    JSON.stringify({
      order_id: payload.order_id,
      status: 'success',
      amount: Number(payload.amount),
      currency: payload.currency,
      end_date: Date.now(),
    }),
  ).toString('base64');
  const forged = await request.post('/webhooks/payments/liqpay', { form: { data, signature: sign(data + 'x') } });
  expect(forged.status()).toBe(401);

  const ok = await request.post('/webhooks/payments/liqpay', { form: { data, signature: sign(data) } });
  expect(ok.status()).toBe(200);
  expect((await ok.json()).ok).toBe(true);
  const replay = await request.post('/webhooks/payments/liqpay', { form: { data, signature: sign(data) } });
  expect((await replay.json()).duplicate).toBe(true);

  await page.goto(returnPath, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('h1')).toContainText('Оплату підтверджено');

  // a callback whose amount does not match the order total is rejected and does not change state
  const wrong = await placeLiqPayOrder(page);
  const badData = Buffer.from(
    JSON.stringify({
      order_id: wrong.payload.order_id,
      status: 'success',
      amount: 0.01,
      currency: wrong.payload.currency,
      end_date: Date.now(),
    }),
  ).toString('base64');
  const mismatch = await request.post('/webhooks/payments/liqpay', {
    form: { data: badData, signature: sign(badData) },
  });
  expect(mismatch.status()).toBe(409);
  await page.goto(wrong.returnPath, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('h1')).not.toContainText('Оплату підтверджено');
});
