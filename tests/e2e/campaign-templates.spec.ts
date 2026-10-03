import { expect, test, type Page } from '@playwright/test';

test.describe.configure({ retries: 0, mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

test('campaign templates are saved, loaded, previewed and deleted on their own, in place', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating data runs once.');
  page.on('dialog', (dialog) => dialog.accept());
  await loginAdmin(page);
  await page.goto('/admin/commerce/campaigns', { waitUntil: 'load' });
  const form = page.locator('form:has(select[name="format"])');
  const name = `E2E template ${Date.now()}`;
  await page.evaluate(() => { (window as unknown as { __kept: boolean }).__kept = true; });

  await form.locator('select[name="format"]').selectOption('html');
  await form.locator('input[name="subject"]').fill('Template subject');
  await form.locator('textarea[name="body"]').fill('<p style="color:#b91c1c">Template <strong>body</strong></p><script>alert(1)</script>');
  await form.locator('[data-template-name]').fill(name);
  await form.locator('[data-template-save]').click();
  await expect(form.locator('[data-template-select] option', { hasText: name })).toHaveCount(1);
  expect(await page.evaluate(() => (window as unknown as { __kept?: boolean }).__kept)).toBe(true); // no reload

  // the preview is the real template around the cleaned body
  await form.locator('[data-campaign-preview]').click();
  const frame = page.frameLocator('[data-campaign-frame]');
  await expect(frame.locator('strong')).toHaveText('body');
  expect(await page.locator('[data-campaign-frame]').getAttribute('srcdoc')).not.toContain('<script');

  // it survives a reload and loads back into the form
  await page.reload({ waitUntil: 'load' });
  const reloaded = page.locator('form:has(select[name="format"])');
  await reloaded.locator('[data-template-select]').selectOption({ label: name });
  await reloaded.locator('[data-template-load]').click();
  await expect(reloaded.locator('input[name="subject"]')).toHaveValue('Template subject');
  await expect(reloaded.locator('select[name="format"]')).toHaveValue('html');
  await expect(reloaded.locator('textarea[name="body"]')).toHaveValue(/Template <strong>body<\/strong>/);
  await expect(reloaded.locator('textarea[name="body"]')).not.toHaveValue(/<script/);

  // deleting the template touches nothing else
  await reloaded.locator('[data-template-delete]').click();
  await expect(reloaded.locator('[data-template-select] option', { hasText: name })).toHaveCount(0);
  await page.reload({ waitUntil: 'load' });
  await expect(page.locator('[data-template-select] option', { hasText: name })).toHaveCount(0);
});

test('subscribers have their own list with search, status and deletion', async ({ page, browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating data runs once.');
  page.on('dialog', (dialog) => dialog.accept());
  const email = `list-${Date.now()}@e2e.test`;
  const ctx = await browser.newContext({ baseURL: testInfo.project.use.baseURL, locale: 'uk-UA' });
  const shop = await ctx.newPage();
  await shop.goto('/', { waitUntil: 'load' });
  await shop.locator('.newsletter-signup__form input[type="email"]').first().fill(email);
  await shop.waitForTimeout(1500); // the spam guard refuses a form sent within a second of rendering
  await shop.locator('.newsletter-signup__form button[type="submit"]').first().click();
  await expect(shop.locator('.newsletter-signup__form input[type="email"]').first()).toHaveValue('');
  await ctx.close();

  await loginAdmin(page);
  await page.goto(`/admin/commerce/subscribers?search=${encodeURIComponent(email)}`, { waitUntil: 'load' });
  const row = page.locator('tbody tr', { hasText: email });
  await expect(row).toHaveCount(1);
  await row.locator('[data-ajax-delete]').click();
  await expect(page.locator('tbody tr', { hasText: email })).toHaveCount(0);
  await page.reload({ waitUntil: 'load' });
  await expect(page.locator('tbody tr', { hasText: email })).toHaveCount(0);
});
