import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

// Release 3.49.0: contacts of an unfinished checkout, icon picker, editable footer, several announcement messages.

test.describe.configure({ mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

test('a shopper who leaves the checkout keeps a lead with the contacts typed so far', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const add = page.locator('form[data-card-add-to-cart] button[type="submit"]').first();
  await expect(add).toBeVisible();
  const added = page.waitForResponse((response) => response.url().includes('/cart/add'));
  await add.click();
  await added;
  await page.goto('/checkout', { waitUntil: 'domcontentloaded' });
  const form = page.locator('[data-checkout-form]');
  await form.locator('input[name="name"]').fill('Lead Tester');
  await form.locator('input[name="phone"]').fill('+380671112233');
  const leadRequest = page.waitForResponse((response) => response.url().endsWith('/checkout/lead') && response.request().method() === 'POST');
  await form.locator('input[name="email"]').fill('lead-e2e@example.test');
  await form.locator('input[name="email"]').blur();
  const response = await leadRequest;
  expect(response.status()).toBe(200);
  expect((await response.json()).ok).toBe(true);
});

test('icons are chosen in a modal that lists the whole library', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await loginAdmin(page);
  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  const field = page.locator('[data-icon-picker]').first();
  await field.scrollIntoViewIfNeeded();
  await field.locator('[data-icon-open]').click();
  const modal = page.locator('[data-icon-dialog]');
  await expect(modal).toBeVisible();
  await expect(modal.locator('[data-icon-count]')).toContainText(/\d{4}/, { timeout: 10_000 });
  await modal.locator('[data-icon-search]').fill('rocket');
  await expect(modal.locator('.admin-icon-modal__item')).toHaveCount(1);
  await modal.locator('.admin-icon-modal__item').first().click();
  await expect(modal).toBeHidden();
  await expect(field.locator('[data-icon-input]')).toHaveValue('rocket');
});

test('the footer links, newsletter text and own column are editable', async ({ page, browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating setting runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await loginAdmin(page);
  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  const form = page.locator('form[data-dirty-guard]');
  const originalTitle = await form.locator('input[name^="footer_nl_title["]').first().inputValue();
  const title = `QA newsletter ${Date.now()}`;
  await form.locator('input[name^="footer_nl_title["]').first().fill(title);
  const faq = form.locator('input[name="footer_link_faq"]');
  const faqWasShown = await faq.isChecked();
  await faq.setChecked(false, { force: true });
  await form.locator('details:has(input[name^="footer_col_title[0]"])').evaluate((d) => { (d as HTMLDetailsElement).open = true; });
  await form.locator('input[name^="footer_col_title[0]"]').first().fill('QA column');
  await form.locator('input[name^="footer_link_label[0][0]"]').first().fill('QA link');
  await form.locator('input[name="footer_link_url[0][0]"]').fill('/catalog');
  await Promise.all([page.waitForURL(/\/admin\/appearance\/storefront/), form.locator('button[type="submit"]').last().click()]);
  try {
    const shop = await browser.newPage();
    await shop.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(shop.locator('.newsletter-signup h2')).toHaveText(title);
    await expect(shop.locator('.site-footer a[href="/faq"]')).toHaveCount(0);
    await expect(shop.locator('.site-footer h2', { hasText: 'QA column' })).toBeVisible();
    await expect(shop.locator('.site-footer a', { hasText: 'QA link' })).toBeVisible();
    await shop.close();
  } finally {
    await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
    const restore = page.locator('form[data-dirty-guard]');
    await restore.locator('input[name^="footer_nl_title["]').first().fill(originalTitle);
    await restore.locator('input[name="footer_link_faq"]').setChecked(faqWasShown, { force: true });
    await restore.locator('details:has(input[name^="footer_col_title[0]"])').evaluate((d) => { (d as HTMLDetailsElement).open = true; });
    await restore.locator('input[name^="footer_col_title[0]"]').first().fill('');
    await restore.locator('input[name^="footer_link_label[0][0]"]').first().fill('');
    await restore.locator('input[name="footer_link_url[0][0]"]').fill('');
    await Promise.all([page.waitForURL(/\/admin\/appearance\/storefront/), restore.locator('button[type="submit"]').last().click()]);
  }
});

test('list pages show readable dates, translated statuses and payment methods', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await loginAdmin(page);
  for (const path of ['/admin/orders', '/admin/shipments', '/admin/commerce/customers', '/admin/commerce/inquiries']) {
    const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
    expect(response?.status()).toBe(200);
    await expectNoServerError(page);
    const text = await page.locator('main').innerText();
    expect(text, `${path} shows microseconds`).not.toMatch(/\d{2}:\d{2}:\d{2}\.\d{3,}/);
    expect(text, `${path} shows raw codes`).not.toMatch(/\b(cash_on_delivery|in_transit|nova_post|quick_order)\b/);
  }
});
