import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

const MOCK = 'http://127.0.0.1:8099';

test('the cash register issues one fiscal receipt for a paid order through Checkbox', async ({ page, request }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating data runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  test.skip(!process.env.PRRO_CHECKBOX_BASE_URL, 'The Checkbox stand-in is not wired for this run.');

  await request.post(`${MOCK}/__reset`);
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);

  await page.goto('/admin/system/prro', { waitUntil: 'domcontentloaded' });
  const form = page.locator('form.admin-form');
  await form.locator('input[name="enabled"]').check({ force: true });
  await form.locator('select[name="environment"]').selectOption('test');
  await form.locator('input[name="login"]').fill('e2e-cashier');
  await form.locator('input[name="password"]').fill('e2e-password');
  await form.locator('input[name="license"]').fill('E2E-LICENSE-KEY');
  await form.locator('select[name="auto"]').selectOption('off');
  await Promise.all([page.waitForLoadState('domcontentloaded'), form.locator('button[name="action"][value="test"]').click()]);
  await expect(page.locator('.admin-notice.is-success')).toBeAttached();

  // A paid order with a refund goes through the register by hand: first the sale, then the return.
  let seeded: { order: string; refund: number } | null = null;
  try {
    seeded = JSON.parse(execFileSync('php', ['tests/e2e/seed-refund.php', 'create'], { encoding: 'utf8', env: process.env, stdio: ['ignore', 'pipe', 'ignore'] }).trim()) as { order: string; refund: number };
  } catch {
    // No paid order to refund: the test is skipped below.
  }
  test.skip(seeded === null, 'No paid order is available to refund.');
  const href = `/admin/orders/${seeded!.order}`;
  await page.goto(href!, { waitUntil: 'domcontentloaded' });
  const issue = page.locator('form[action$="/fiscalize"] button[type="submit"]');
  await expect(issue).toBeVisible();
  await Promise.all([page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/fiscalize')), issue.click()]);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('.admin-notice.is-success')).toBeAttached();
  await expect(page.locator('main')).toContainText('MOCK-FISCAL-001');

  const calls = (await (await request.get(`${MOCK}/__log`)).json()) as Array<{ method: string; payload: Record<string, unknown>; license?: string }>;
  const sale = calls.find((c) => c.method === 'checkbox/receipts/sell');
  expect(sale, 'a sale receipt must reach the register').toBeTruthy();
  expect(sale!.license).toBe('E2E-LICENSE-KEY');
  const goods = sale!.payload.goods as Array<{ good: { price: number }; quantity: number; discounts?: Array<{ value: number }> }>;
  const payments = sale!.payload.payments as Array<{ value: number }>;
  const goodsSum = goods.reduce((s, g) => s + Math.round((g.good.price * g.quantity) / 1000) - (g.discounts?.[0]?.value ?? 0), 0);
  expect(goodsSum, 'goods after discounts must equal the payment').toBe(payments[0].value);

  // The return receipt points at the sale receipt and marks the goods as returned.
  const returnButton = page.locator('form[action*="/fiscalize-refund/"] button[type="submit"]');
  await expect(returnButton).toBeVisible();
  await returnButton.click();
  await expect(page.locator('[data-admin-confirm]')).toBeVisible();
  await Promise.all([page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/fiscalize-refund/')), page.locator('[data-admin-confirm] [data-confirm-accept]').click()]);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('.admin-notice.is-success')).toBeAttached();
  const after = (await (await request.get(`${MOCK}/__log`)).json()) as Array<{ method: string; payload: Record<string, unknown> }>;
  const sales = after.filter((c) => c.method === 'checkbox/receipts/sell');
  expect(sales).toHaveLength(2);
  expect(sales[1].payload.related_receipt_id).toBe(sales[0].payload.id);
  expect((sales[1].payload.goods as Array<{ is_return?: boolean }>)[0].is_return).toBe(true);

  // Once only: a second press does not send another receipt.
  await page.goto(href!, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('form[action$="/fiscalize"]')).toHaveCount(0);

  execFileSync('php', ['tests/e2e/seed-refund.php', 'delete', String(seeded!.refund)], { encoding: 'utf8', env: process.env });

  // Leave the register off for other tests.
  await page.goto('/admin/system/prro', { waitUntil: 'domcontentloaded' });
  await page.locator('form.admin-form input[name="enabled"]').uncheck({ force: true });
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form.admin-form button[type="submit"]:not([name])').click()]);
});
