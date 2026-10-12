import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

// Release 3.97.24: the affiliate programme — a link, a cookie, an order that earns commission, a payout.

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

const sql = (q: string): string => execFileSync('php', ['bin/console', 'dbal:run-sql', q], { encoding: 'utf8', env: process.env });

test('a partner link marks the visitor, the order earns commission once, and the payout is recorded', async ({ page, browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Creates data once.');
  test.setTimeout(300_000);
  page.setDefaultNavigationTimeout(120_000);
  await loginAdmin(page);

  // a partner with 10 percent
  await page.goto('/admin/commerce/affiliates', { waitUntil: 'domcontentloaded' });
  const name = 'Партнер ' + Date.now();
  const form = page.locator('[data-affiliate-form]');
  await form.locator('input[name="name"]').fill(name);
  await form.locator('input[name="percent"]').fill('10');
  await Promise.all([page.waitForResponse((r) => r.url().endsWith('/affiliates/create') && r.request().method() === 'POST'), form.locator('button[type="submit"]').click()]);
  await page.goto('/admin/commerce/affiliates', { waitUntil: 'domcontentloaded' });
  const row = page.locator('[data-affiliate]').filter({ hasText: name });
  await expect(row).toHaveCount(1);
  const code = (await row.getAttribute('data-affiliate'))!;
  expect(code).toMatch(/^[a-z0-9]{8}$/);
  await expect(row.locator('code')).toContainText('/?ref=' + code);

  // a visitor follows the link: the cookie is set once, the visit counted once
  const visitor = await browser.newContext({ baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8000' });
  const shop = await visitor.newPage();
  shop.setDefaultNavigationTimeout(120_000);
  await shop.goto('/?ref=' + code, { waitUntil: 'domcontentloaded' });
  expect((await visitor.cookies()).find((c) => c.name === 'mc_ref')?.value).toBe(code);
  await shop.goto('/?ref=' + code, { waitUntil: 'domcontentloaded' });
  await shop.goto('/?ref=zzzzzzzz', { waitUntil: 'domcontentloaded' }); // an unknown code changes nothing
  expect((await visitor.cookies()).find((c) => c.name === 'mc_ref')?.value).toBe(code);
  expect(sql(`SELECT COUNT(*) AS n FROM mc_affiliate_click c JOIN mc_affiliate a ON a.id=c.affiliate_id WHERE a.code='${code}'`)).toMatch(/\b1\s*$/m);

  // the visitor orders from the storefront
  await shop.goto('/catalog', { waitUntil: 'networkidle' });
  const card = shop.locator('[data-product-card]').filter({ has: shop.locator('form[data-card-add-to-cart]') }).first();
  const added = shop.waitForResponse((r) => r.url().endsWith('/cart/add') && r.request().method() === 'POST');
  await card.locator('form[data-card-add-to-cart] button[type="submit"]').click();
  expect((await (await added).json()).ok).toBe(true);
  await shop.goto('/checkout', { waitUntil: 'domcontentloaded' });
  const accept = shop.locator('[data-consent-accept-all]').first();
  if (await accept.isVisible().catch(() => false)) await accept.click();
  await shop.locator('[data-checkout-form] input[name="name"]').fill('Покупець Партнера');
  await shop.locator('[data-checkout-form] input[name="phone"]').fill('+380501112244');
  await shop.locator('.ck-choice[data-carrier="self_pickup"]').click();
  await shop.locator('input[name="payment_method"][value="cash_on_delivery"]').check();
  await Promise.all([shop.waitForURL(/\/checkout\/success\//, { timeout: 60_000 }), shop.locator('button.place-order').click()]);
  await visitor.close();

  // the commission is 10 percent of the goods; a completed order makes it due, the payout closes it
  const credited = sql(`SELECT ao.base_minor,ao.commission_minor FROM mc_affiliate_order ao JOIN mc_affiliate a ON a.id=ao.affiliate_id WHERE a.code='${code}'`);
  const numbers = (credited.match(/\b\d{3,}\b/g) ?? []).map(Number);
  expect(numbers.length).toBeGreaterThanOrEqual(2);
  expect(numbers[1]).toBe(Math.round(numbers[0] * 0.1));

  await page.goto('/admin/commerce/affiliates', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-affiliate="' + code + '"] td').nth(4)).toHaveText('1'); // one order
  sql(`UPDATE mc_sales_order SET status='completed' WHERE id=(SELECT order_id FROM mc_affiliate_order WHERE affiliate_id=(SELECT id FROM mc_affiliate WHERE code='${code}') LIMIT 1)`);
  await page.goto('/admin/commerce/affiliates', { waitUntil: 'domcontentloaded' });
  const pay = page.locator('[data-affiliate="' + code + '"]').locator('button:not([disabled])', { hasText: /Виплатити|Pay out/ });
  await expect(pay).toHaveCount(1);
  await pay.click();
  await Promise.all([page.waitForResponse((r) => r.url().includes('/pay') && r.request().method() === 'POST'), page.locator('[data-confirm-accept]').click()]);
  expect(sql(`SELECT payout_status FROM mc_affiliate_order ao JOIN mc_affiliate a ON a.id=ao.affiliate_id WHERE a.code='${code}'`)).toContain('paid');
});
