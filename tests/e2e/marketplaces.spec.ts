import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';
import { loginAdmin } from './helpers';

// Release 3.97.24: Prom.ua orders become shop orders once, stock and status go back. Runs against the stand-in on :8099.

const MOCK = 'http://127.0.0.1:8099';

type PromLogEntry = {
  path: string;
  auth?: string;
  query?: { limit?: string };
  body: Array<{ id: string; quantity_in_stock: number; presence: string }> & { status?: string; ids?: number[] };
};

async function saveProm(page: Page, values: Record<string, string>): Promise<void> {
  await page.goto('/admin/system/integrations/connections/marketplaces', { waitUntil: 'domcontentloaded' });
  const section = page.locator('#conn-prom');
  await section.locator('summary').click();
  for (const [name, value] of Object.entries(values)) {
    const field = section.locator(`[name="key[${name}]"]`);
    if ((await field.evaluate((e) => e.tagName)) === 'SELECT') await field.selectOption(value);
    else await field.fill(value);
  }
  await Promise.all([page.waitForURL(/connections\/marketplaces/), section.locator('button[type="submit"]').click()]);
}

test('a Prom.ua order is taken once, stock is pushed, the status goes back', async ({ page, request }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared settings once.');
  test.setTimeout(300_000);
  const up = await request.get(`${MOCK}/__log`).then((r) => r.ok()).catch(() => false);
  test.skip(!up, 'The stand-in server is not running.');
  await request.post(`${MOCK}/__prom-reset`);
  for (const sql of ['DELETE FROM mc_marketplace_order', 'DELETE FROM mc_marketplace_stock', 'DELETE FROM mc_marketplace_state', 'DELETE FROM mc_marketplace_log']) {
    execFileSync('php', ['bin/console', 'dbal:run-sql', sql], { env: process.env, stdio: 'ignore' });
  }
  page.setDefaultNavigationTimeout(120_000);
  await loginAdmin(page);

  try {
    await saveProm(page, { PROM_ENABLED: '1', PROM_API_TOKEN: 'e2e-prom-token', PROM_API_BASE: `${MOCK}/prom/api/v1` });
    expect(await page.content()).not.toContain('e2e-prom-token');

    await request.post(`${MOCK}/__prom-seed`, {
      data: [
        { id: 9001, status: 'pending', client_first_name: 'Марія', client_last_name: 'Prom', phone: '+380509990011', email: '', client_notes: 'e2e', delivery_option: { name: 'Нова пошта' }, delivery_address: 'Львів, відділення 5',
          products: [{ sku: 'DEMO-078', external_id: 'not-ours', quantity: 2, price: '150,00 UAH' }] },
        { id: 9002, status: 'canceled', client_first_name: 'X', products: [{ sku: 'DEMO-078', quantity: 1, price: '1' }] },
        { id: 9003, status: 'pending', client_first_name: 'Невідомий', phone: '+380509990022', products: [{ sku: 'NO-SUCH-SKU', quantity: 1, price: '10' }] },
      ],
    });

    // the button runs a pass at once
    await Promise.all([page.waitForURL(/connections\/marketplaces/), page.locator('form[action$="/marketplaces/run"] button').click()]);
    await expect(page.locator('[data-toast-source]').first()).toBeAttached();
    const log: PromLogEntry[] = await (await request.get(`${MOCK}/__prom-log`)).json();
    const itemsIn = (calls: PromLogEntry[]): number => calls.filter((c) => c.path === '/products/edit_by_external_id').reduce((n, c) => n + c.body.length, 0);
    const pushedOnce = itemsIn(log);
    expect(log.find((c) => c.path === '/orders/list')?.auth).toBe('Bearer e2e-prom-token');
    expect(log.find((c) => c.path === '/orders/list')?.query.limit).toBe('100');

    // order 9001 is a shop order with the price of Prom and the delivery text; 9002 (cancelled) and 9003 (unknown SKU) are not
    await page.goto('/admin/orders?search=%D0%9C%D0%B0%D1%80%D1%96%D1%8F', { waitUntil: 'domcontentloaded' });
    const found = page.locator('tr', { hasText: 'Марія Prom' }).locator('a[href*="/admin/orders/"]').first();
    await expect(page.locator('body')).toContainText('Марія Prom');
    await expect(page.locator('body')).not.toContainText('Невідомий');
    await Promise.all([page.waitForURL(/\/admin\/orders\/[0-9a-f-]{36}/), found.click()]);
    await expect(page.locator('body')).toContainText('PROM #9001');
    await expect(page.locator('body')).toContainText('відділення 5');

    // the same order is not taken twice, the unknown product is in the log
    const second = execFileSync('php', ['bin/console', 'commerce:marketplace:sync', '--only=prom'], { encoding: 'utf8', env: process.env, stdio: ['ignore', 'pipe', 'pipe'] }).toString();
    expect(second).toContain('orders 0');
    await page.goto('/admin/system/integrations/connections/marketplaces', { waitUntil: 'domcontentloaded' });
    await page.locator('details.admin-more:has-text("Журнал"), details.admin-more:has-text("Log")').last().locator('summary').click();
    await expect(page.locator('body')).toContainText('NO-SUCH-SKU');

    // stock and prices were pushed once, with the variant UUID as the id and in the shop's own numbers
    const after: PromLogEntry[] = await (await request.get(`${MOCK}/__prom-log`)).json();
    const stockCalls = after.filter((c) => c.path === '/products/edit_by_external_id');
    expect(stockCalls.length).toBeGreaterThan(0);
    const first = stockCalls[0].body[0];
    expect(first.id).toMatch(/^[0-9a-f-]{36}$/);
    expect(typeof first.quantity_in_stock).toBe('number');
    expect(['available', 'not_available']).toContain(first.presence);
    // nothing changed between the passes, so the second pass pushed nothing new
    expect(pushedOnce).toBeGreaterThan(0);
    expect(itemsIn(after)).toBe(pushedOnce);

    // a new order has no status to tell yet; once the manager confirms it, the next pass tells Prom ("received")
    expect(after.find((c) => c.path === '/orders/set_status')).toBeUndefined();
    execFileSync('php', ['bin/console', 'dbal:run-sql', "UPDATE mc_sales_order SET status='confirmed' WHERE customer_comment LIKE '[PROM #9001]%'"], { env: process.env, stdio: 'ignore' });
    execFileSync('php', ['bin/console', 'commerce:marketplace:sync', '--only=prom'], { env: process.env, stdio: 'ignore' });
    const sent: PromLogEntry[] = await (await request.get(`${MOCK}/__prom-log`)).json();
    expect(sent.find((c) => c.path === '/orders/set_status')?.body).toEqual({ status: 'received', ids: [9001] });
    execFileSync('php', ['bin/console', 'commerce:marketplace:sync', '--only=prom'], { env: process.env, stdio: 'ignore' });
    expect((await (await request.get(`${MOCK}/__prom-log`)).json()).filter((c: PromLogEntry) => c.path === '/orders/set_status')).toHaveLength(1); // told once
  } finally {
    await saveProm(page, { PROM_ENABLED: '0' });
  }
});
