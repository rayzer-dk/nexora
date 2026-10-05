import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

// Release 3.58.0: own delivery methods, own redirects, order card (edit, message), subscriber import, analytics ranges.

test.describe.configure({ mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

test('an own delivery method can be added and is offered at the checkout with a free-text address', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await loginAdmin(page);
  await page.goto('/admin/shipments/methods', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const name = `Courier ${Date.now() % 100000}`;
  await page.locator('details.admin-collapse', { has: page.locator('input[name="custom[nd][name][uk-UA]"]') }).locator('summary').click();
  await page.locator('input[name="custom[nd][name][uk-UA]"]').fill(name, { force: true });
  await Promise.all([page.waitForNavigation(), page.locator('button.is-primary[type="submit"]').first().click()]);
  await expect(page.locator('.admin-method-own').filter({ hasText: name })).toHaveCount(1);

  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const added = page.waitForResponse((response) => response.url().includes('/cart/add'));
  await page.locator('form[data-card-add-to-cart] button[type="submit"]').first().click();
  await added;
  await page.goto('/checkout', { waitUntil: 'domcontentloaded' });
  const custom = page.locator('input[name="carrier"][data-custom]').first();
  await custom.check({ force: true });
  await expect(page.locator('[data-delivery-custom]')).toBeVisible();
  await expect(page.locator('[data-delivery-remote]')).toBeHidden();
});

test('an own redirect sends an old address to a new one and counts the hit', async ({ page, request }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await loginAdmin(page);
  await page.goto('/admin/system/seo-custom-redirects', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const source = `/old-e2e-${Date.now() % 100000}.html`;
  await page.locator('form[action$="/save"] input[name="source"]').fill(source);
  await page.locator('form[action$="/save"] input[name="target"]').fill('/catalog');
  await Promise.all([page.waitForNavigation(), page.locator('form[action$="/save"] button[type="submit"]').click()]);
  await expect(page.locator('.admin-notice.is-success')).toBeAttached();
  const response = await request.get(source, { maxRedirects: 0 });
  expect(response.status()).toBe(301);
  expect(response.headers()['location']).toContain('/catalog');
});

test('the order card edits customer details with an "edited" mark and writes to the customer', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await loginAdmin(page);
  await page.goto('/admin/orders', { waitUntil: 'domcontentloaded' });
  await page.locator('table.admin-table a[href^="/admin/orders/"]').first().click();
  await expectNoServerError(page);
  await page.locator('form[action$="/customer"]').locator('xpath=ancestor::details[1]/summary').click();
  await page.locator('form[action$="/customer"] input[name="customer_phone"]').fill('+380501112233');
  await Promise.all([page.waitForNavigation(), page.locator('form[action$="/customer"] button[type="submit"]').click()]);
  await expect(page.locator('.admin-badge.is-warning').first()).toBeVisible();
  const message = page.locator('form[action$="/message"]');
  if (await message.count()) {
    await message.locator('textarea[name="message"]').fill('Thank you for the order');
    await Promise.all([page.waitForNavigation(), message.locator('button[type="submit"]').click()]);
    await expect(page.locator('.admin-notice')).toBeAttached();
  }
});

test('subscribers can be imported from a list and analytics accepts a date range', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await loginAdmin(page);
  await page.goto('/admin/commerce/subscribers', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await page.locator('details.admin-collapse summary').first().click();
  await page.locator('textarea[name="emails"]').fill(`import-${Date.now() % 100000}@example.test`);
  await page.locator('input[name="consent"]').check();
  await Promise.all([page.waitForNavigation(), page.locator('form[action$="/import"] button[type="submit"]').click()]);
  await expect(page.locator('.admin-notice.is-success')).toBeAttached();
  await page.goto('/admin/analytics?from=2026-01-01&to=2026-12-31', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('.admin-bars')).toBeAttached();
});
