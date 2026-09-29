import { expect, test } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0 });

test('EU withdrawal function: footer link, two-step flow, acknowledgement and admin visibility', async ({ page, browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Creates a notice once per CI database.');
  const order = 'E2E-WD-' + Date.now();

  await page.goto('/', { waitUntil: 'domcontentloaded' });
  const link = page.locator('a[data-withdrawal-link]');
  await expect(link).toBeVisible();
  await link.click();
  await expect(page).toHaveURL(/\/withdrawal$/);
  await expectNoServerError(page);

  const form = page.locator('form[data-withdrawal-form]');
  // Invalid input is rejected before the confirmation step.
  await form.locator('input[name="name"]').fill('E2E Consumer');
  await form.locator('input[name="email"]').fill('not-an-email');
  await form.locator('input[name="order"]').fill(order);
  await page.evaluate(() => document.querySelector('[data-withdrawal-form] input[name="email"]')?.setAttribute('type', 'text'));
  await page.locator('[data-withdrawal-start]').click();
  await expect(page.locator('.store-notice.is-error')).toBeVisible();

  await form.locator('input[name="email"]').fill('consumer@example.test');
  await page.locator('[data-withdrawal-start]').click();
  await expect(page.locator('[data-withdrawal-summary]')).toContainText(order);
  await expect(page.locator('[data-withdrawal-confirm]')).toBeVisible();
  await page.locator('[data-withdrawal-confirm]').click();
  await expect(page.locator('[data-withdrawal-done]')).toBeVisible();
  await expect(page.locator('[data-withdrawal-reference]')).toHaveText(/^WD-[0-9A-F]{10}$/);
  expect((await page.request.get('/withdrawal')).headers()['cache-control']).toContain('no-store');

  // Accessibility statement is public and linked.
  await page.goto('/accessibility', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('[data-accessibility-statement] h1')).toBeVisible();

  // The merchant sees the notice.
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');
  const context = await browser.newContext();
  const admin = await context.newPage();
  await admin.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await admin.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await admin.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await admin.locator('button[type="submit"]').click();
  await admin.waitForURL(/\/admin(?:\/|$)/);
  await admin.goto('/admin/customer-experience', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(admin);
  await expect(admin.locator('[data-withdrawal-notices]')).toContainText(order);
  await context.close();
});
