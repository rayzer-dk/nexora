import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

// Release 3.40.0: SMS to customers — settings page, live counter with encoding rules, manual SMS from an order, log.

async function login(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')),
    page.locator('button[type="submit"]').click(),
  ]);
}

test('SMS page: counter switches between GSM-7 and UCS-2, settings are saved, a manual SMS from an order is logged', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings run once.');
  await login(page);
  await page.goto('/admin/commerce/sms', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);

  const area = page.locator('textarea[name="tpl_placed"]');
  const counter = area.locator('xpath=ancestor::label').locator('[data-sms-counter-out]');
  await area.fill('Order 1001 shipped');
  await expect(counter).toContainText('GSM-7');
  await expect(counter).toContainText('18');
  await area.fill('Замовлення 1001 відправлено');
  await expect(counter).toContainText('UCS-2');
  await area.fill('я'.repeat(71));
  await expect(counter).toContainText('SMS: 2');
  await expect(page.locator('.admin-sms-limits').first()).toContainText('70');

  try {
    await page.locator('input[name="enabled"]').check();
    await page.locator('select[name="driver"]').selectOption('smsfly');
    await page.locator('input[name="endpoint"]').fill('https://sms.example.com/send');
    await page.locator('input[name="sender"]').fill('E2EShop');
    await area.fill('');
    await Promise.all([
      page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith('/admin/commerce/sms')),
      page.locator('form[action$="/admin/commerce/sms"] button[type="submit"]').click(),
    ]);
    await page.goto('/admin/commerce/sms', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('input[name="endpoint"]')).toHaveValue('https://sms.example.com/send');
    await expect(page.locator('input[name="enabled"]')).toBeChecked();
    await expect(page.locator('select[name="driver"]')).toHaveValue('smsfly');

    // manual SMS from an order: the gateway is not reachable in the test, so the attempt is logged as failed
    await page.goto('/admin/orders', { waitUntil: 'domcontentloaded' });
    const href = await page.locator('table.admin-table tbody a[href^="/admin/orders/"]').first().getAttribute('href');
    expect(href).toBeTruthy();
    await page.goto(href!, { waitUntil: 'domcontentloaded' });
    const panel = page.locator('#order-sms');
    await expect(panel).toBeVisible();
    await panel.locator('input[name="phone"]').fill('0501234567');
    await panel.locator('select[data-sms-template]').selectOption({ index: 1 });
    await expect(panel.locator('textarea[name="text"]')).not.toHaveValue('');
    await expect(panel.locator('[data-sms-counter-out]')).not.toHaveText('');
    await Promise.all([page.waitForURL(/\/admin\/orders\//), panel.locator('button[type="submit"]').click()]);
    await expectNoServerError(page);
    await expect(page.locator('#order-sms .admin-sms-history li').first().locator('.admin-badge')).toHaveClass(/is-danger/); // the test gateway does not resolve
  } finally {
    await page.goto('/admin/commerce/sms', { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="enabled"]').uncheck();
    await Promise.all([
      page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith('/admin/commerce/sms')),
      page.locator('form[action$="/admin/commerce/sms"] button[type="submit"]').click(),
    ]);
  }
});
