import { expect, test } from '@playwright/test';
import { loginAdmin } from './helpers';

// Release 3.97.24: API keys in the admin and the write side of the public API (prices, stock, order progress).

test('an API key is made in the admin and changes the price and stock of a variant', async ({ page, request }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared demo data once.');
  test.setTimeout(120_000);
  await loginAdmin(page);
  await page.goto('/admin/system/integrations/api', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="name"]').fill(`E2E sync ${Date.now()}`);
  await page.locator('[data-scope-preset*="inventory:write"]').click();
  await Promise.all([page.waitForURL(/integrations\/api/), page.locator('form[action$="/api/create"] button[type="submit"]').click()]);
  const key = (await page.locator('#api-secret').innerText()).trim();
  expect(key.length).toBeGreaterThan(40);
  const auth = { Authorization: `Bearer ${key}` };

  // a read key is not enough to write, a sync key is
  const list = await request.get('/api/v1/catalog/products?limit=1', { headers: auth });
  expect(list.status()).toBe(200);
  const variantSku = (await list.json()).data[0].sku as string;
  const before = await request.patch(`/api/v1/catalog/variants/by-sku/${encodeURIComponent(variantSku)}`, { headers: auth, data: { price_minor: 123400, stock_quantity: 41 } });
  expect(before.status()).toBe(200);
  expect((await before.json()).data.price_minor).toBe(123400);
  expect((await before.json()).data.stock_quantity).toBe('41');
  const delta = await request.patch(`/api/v1/catalog/variants/by-sku/${encodeURIComponent(variantSku)}`, { headers: auth, data: { stock_delta: -3 } });
  expect((await delta.json()).data.stock_quantity).toBe('38');

  // a batch reports each item and replays an identical retry
  const body = { items: [{ sku: variantSku, price_minor: 99900 }, { sku: 'NO-SUCH-SKU', stock_quantity: 1 }, { sku: variantSku, stock_quantity: -1 }] };
  const headers = { ...auth, 'Idempotency-Key': `e2e-batch-${Date.now()}-abcdef` };
  const batch = await request.post('/api/v1/catalog/sync', { headers, data: body });
  expect(batch.status()).toBe(200);
  const result = (await batch.json()).data;
  expect(result.applied).toBe(1);
  expect(result.failed).toBe(2);
  expect(result.results[1].error).toBe('variant_not_found');
  const replay = await request.post('/api/v1/catalog/sync', { headers, data: body });
  expect((await replay.json()).data.applied).toBe(1);

  // without a key or with a wrong body nothing is written
  expect((await request.patch(`/api/v1/catalog/variants/by-sku/${encodeURIComponent(variantSku)}`, { data: { price_minor: 1 } })).status()).toBe(401);
  expect((await request.patch(`/api/v1/catalog/variants/by-sku/${encodeURIComponent(variantSku)}`, { headers: auth, data: { price_minor: -5 } })).status()).toBe(422);

  // an order: tracking number and shipped
  const orders = await request.get('/api/v1/orders?limit=1', { headers: auth });
  const order = (await orders.json()).data[0];
  const shipped = await request.patch(`/api/v1/orders/${order.public_id}`, { headers: auth, data: { fulfillment_status: 'shipped', tracking_number: '59000111222333', note: 'e2e api' } });
  expect(shipped.status()).toBe(200);
  const after = (await shipped.json()).data;
  expect(after.fulfillment_status).toBe('shipped');
  expect(after.delivery.tracking_number).toBe('59000111222333');

  // switch the key off
  await page.goto('/admin/system/integrations/api', { waitUntil: 'domcontentloaded' });
  await page.locator('form[action$="/revoke"] button').first().evaluate((el: HTMLElement) => { (el.closest('form') as HTMLFormElement).removeAttribute('data-confirm'); });
  await Promise.all([page.waitForURL(/integrations\/api/), page.locator('form[action$="/revoke"] button').first().click()]);
  expect((await request.get('/api/v1/catalog/products?limit=1', { headers: auth })).status()).toBe(401);
});
