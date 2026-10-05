import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

// Release 3.62.0: guest reviews, storefront search by sound and keyboard layout, admin quick buttons and search.

test.describe.configure({ mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

test('a visitor without an account can leave a review that waits for moderation', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  await page.locator('[data-product-card] h2 a').first().click();
  await page.locator('[role="tab"][href="#reviews"], [data-tab="reviews"], a[href="#reviews"]').first().click();
  const form = page.locator('#reviews form.feedback-form');
  await expect(form).toBeAttached();
  await form.locator('input[name="author"]').fill('Guest Tester');
  await form.locator('input[name="guest_email"]').fill('guest-review@example.test');
  await form.locator('textarea[name="body"]').fill('Works well, guest review from the end-to-end test.');
  await page.waitForTimeout(1500);
  await form.locator('button[type="submit"]').click();
  await page.waitForTimeout(1500);
  await loginAdmin(page);
  await page.goto('/admin/customer-experience', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('body')).toContainText('guest-review@example.test');
});

test('a header quick button is added and the admin palette finds a page by the other keyboard layout', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await loginAdmin(page);
  await page.goto('/admin/account/quick-links', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const form = page.locator('[data-quicklink-form]');
  await expect(form.locator('select[name="href"] option').first()).toBeAttached();
  await form.locator('select[name="href"]').selectOption('/admin/orders');
  await Promise.all([page.waitForURL(/quick-links/), form.locator('button[type="submit"]').click()]);
  await expect(page.locator('a.admin-quick-link[href="/admin/orders"]')).toBeVisible();
  await page.locator('[data-command-open]').click();
  await page.locator('[data-command-input]').fill('ordrs');
  await expect(page.locator('[data-command-results] a[href$="/admin/orders"]').first()).toBeVisible();
  await page.locator('[data-command-input]').fill('щквукы');
  await expect(page.locator('[data-command-results] a[href$="/admin/orders"]').first()).toBeVisible();
  // cleanup so the header stays as it was
  await page.keyboard.press('Escape');
  await page.goto('/admin/account/quick-links', { waitUntil: 'domcontentloaded' });
  await page.locator('form[action$="/remove"] button').first().evaluate((el: HTMLElement) => { const f = el.closest('form') as HTMLFormElement; f.removeAttribute('data-confirm'); f.submit(); });
});
