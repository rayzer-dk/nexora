import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { loginAdmin } from './helpers';

// Release 3.97.24: orders are sent to a CRM or any address with a body template of the shop owner.

const HOOK = 'http://127.0.0.1:8099/crm-hook';

test('an order connection sends the new order signed, with the preset body and the secret header', async ({ page, request }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared settings once.');
  test.setTimeout(240_000);
  await request.post('http://127.0.0.1:8099/__crm-reset');
  await loginAdmin(page);

  // a connection from the SalesDrive preset with a secret header
  await page.goto('/admin/system/integrations/orders?new=1', { waitUntil: 'domcontentloaded' });
  const form = page.locator('[data-crm-form]');
  await form.locator('input[name="name"]').fill('E2E receiver');
  await form.locator('select[name="preset"]').selectOption('salesdrive');
  await expect(form.locator('textarea[name="body_template"]')).toHaveValue(/costPerItem/);
  await form.locator('select[name="preset"]').selectOption('json');
  await expect(form.locator('textarea[name="body_template"]')).toHaveValue(/"items"/);
  await form.locator('input[name="url"]').fill(HOOK);
  await form.locator('textarea[name="headers_text"]').fill('Authorization: Bearer e2e-secret-token');
  await form.locator('input[name="events[]"][value="placed"]').check();
  await Promise.all([page.waitForURL(/orders\?edit=\d+/), form.locator('button[type="submit"]:not([formaction])').click()]);
  expect(await page.content()).not.toContain('e2e-secret-token');
  await expect(page.locator('textarea[name="headers_text"]')).toHaveValue(/Authorization: \*{8}/);
  const secret = await page.locator('input[readonly]').first().inputValue();
  expect(secret.length).toBeGreaterThan(30);

  // a broken template is refused with a message
  await page.locator('textarea[name="body_template"]').fill('{ "x": {{ order.number }} }');
  await page.locator('form[data-crm-form] button[type="submit"]:not([formaction])').click();
  await page.waitForURL(/orders/);
  await expect(page.locator('.admin-notice.is-error, [data-toast-source]').first()).toBeAttached();

  // the test button sends the latest order at once
  await page.goto(page.url().split('#')[0], { waitUntil: 'domcontentloaded' });
  const editUrl = page.url();
  await page.locator('form[data-crm-form] button[formaction$="/test"]').click();
  await page.waitForURL(/orders\?edit=\d+/);
  const afterTest = await (await request.get('http://127.0.0.1:8099/__crm-log')).json();
  expect(afterTest.length).toBe(1);
  expect(afterTest[0].auth).toBe('Bearer e2e-secret-token');
  const sent = JSON.parse(afterTest[0].body);
  expect(sent.order.number).toBeTruthy();
  expect(Array.isArray(sent.items)).toBe(true);
  expect(afterTest[0].signature).toBe('sha256=' + createHmac('sha256', secret).update(afterTest[0].body).digest('hex'));

  // a real new order goes out through the event queue
  await page.goto('/admin/orders/manual', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="name"]').fill('CRM Клієнт');
  await page.locator('input[name="phone"]').fill('+380501112299');
  await page.locator('input[name="sku[]"]').first().fill('DEMO-078');
  await page.locator('input[name="qty[]"]').first().fill('1');
  await page.locator('input[name="delivery"]').fill('Київ, відділення 7');
  await Promise.all([page.waitForURL(/\/admin\/orders\/[0-9a-f-]{36}/), page.locator('main form button[type="submit"]').last().click()]);
  execFileSync('php', ['bin/console', 'commerce:events:work', '--limit=200', '--no-interaction'], { encoding: 'utf8', env: process.env });
  execFileSync('php', ['bin/console', 'commerce:integration:work', '--limit=50', '--no-interaction'], { encoding: 'utf8', env: process.env });
  const log = await (await request.get('http://127.0.0.1:8099/__crm-log')).json();
  expect(log.length).toBe(2);
  const placed = JSON.parse(log[1].body);
  expect(log[1].event).toBe('placed');
  expect(placed.customer.name).toBe('CRM Клієнт');
  expect(placed.delivery.manual).toContain('відділення 7');
  expect(placed.items[0].sku).toBe('DEMO-078');

  // the log page shows both sends, then the connection is removed
  await page.goto('/admin/system/integrations/orders', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('section.admin-panel').last().locator('tbody tr')).toHaveCount(2);
  await page.locator('form[action$="/delete"]').first().evaluate((f: HTMLFormElement) => f.removeAttribute('data-confirm'));
  await Promise.all([page.waitForURL(/integrations\/orders$/), page.locator('form[action$="/delete"] button').first().click()]);
  expect(editUrl).toContain('edit=');
});
