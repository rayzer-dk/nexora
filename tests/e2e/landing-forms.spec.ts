import { expect, test } from '@playwright/test';
import { expectNoServerError } from './helpers';

test('a landing button opens a configured request form and the request reaches the admin', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating site profile runs once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  const who = `Landing Lead ${Date.now()}`;
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);

  const setMode = async (mode: string) => {
    await page.goto('/admin/system/site', { waitUntil: 'domcontentloaded' });
    await page.locator(`input[name="mode"][value="${mode}"]`).check({ force: true });
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form[data-site-mode-profiles] button[type="submit"]').first().click()]);
  };

  try {
    await setMode('landing');
    await page.goto('/admin/appearance/landing?locale=uk-UA', { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="hero[primary_url]"]').fill('#form-consult');
    // Consultation form: a custom field (a list) next to the phone.
    const form = page.locator('details').filter({ hasText: '#form-consult' });
    await form.locator('summary').click();
    await form.locator('select[name="forms[3][fields][2][type]"]').selectOption('select');
    await form.locator('input[name="forms[3][fields][2][label]"]').fill('Тема звернення');
    await form.locator('input[name="forms[3][fields][2][options]"]').fill('Ціни|Гарантія');
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form[action$="/landing/save"] button[type="submit"]:not([name])').click()]);
    await expectNoServerError(page);

    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const consent = page.locator('[data-commerce-consent] button').last();
    if (await consent.isVisible()) await consent.click();
    await page.locator('.lp-header .lp-btn--primary').click();
    const dialog = page.locator('dialog#form-consult');
    await expect(dialog).toBeVisible();
    await dialog.locator('input[name="name"]').fill(who);
    await dialog.locator('input[name="phone"]').fill('+380501112233');
    await dialog.locator('select').selectOption('Гарантія');
    await page.waitForTimeout(1500);
    const sent = page.waitForResponse((r) => r.url().endsWith('/contact/send') && r.request().method() === 'POST');
    await dialog.locator('button[type="submit"]').click();
    expect([200, 302, 303]).toContain((await sent).status());
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.store-notice.is-success').first()).toBeVisible();

    await page.goto('/admin/commerce/inquiries', { waitUntil: 'domcontentloaded' });
    await expect(page.getByText(who).first()).toBeVisible();
    await expect(page.getByText('Тема звернення: Гарантія').first()).toBeVisible();
  } finally {
    await page.goto('/admin/appearance/landing?locale=uk-UA', { waitUntil: 'domcontentloaded' });
    await page.locator('button[name="reset"]').click();
    await page.locator('[data-confirm-accept]').click();
    await page.waitForLoadState('domcontentloaded');
    await setMode('hybrid');
  }
});
