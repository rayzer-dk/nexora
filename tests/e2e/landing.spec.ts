import { expect, test } from '@playwright/test';
import { expectNoServerError } from './helpers';

test('a landing-profile site opens on the one-page landing, editable from the admin', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating site profile runs once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  const marker = `Landing headline ${Date.now()}`;
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);

  const setMode = async (mode: string) => {
    await page.goto('/admin/system/site', { waitUntil: 'domcontentloaded' });
    await page.locator(`input[name="mode"][value="${mode}"]`).check({ force: true });
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form[data-site-mode-profiles] button[type="submit"]').first().click()]);
    await expectNoServerError(page);
  };

  try {
    await setMode('landing');
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    await expect(page.locator('.lp-hero h1')).toBeVisible();
    await expect(page.locator('.lp-card')).toHaveCount(6);
    await expect(page.locator('.lp-faq details')).toHaveCount(5);
    await expect(page.locator('form.lp-form')).toBeVisible();
    // The shop is switched off in this profile.
    expect((await page.goto('/cart'))?.status()).toBe(404);

    await page.goto('/admin/appearance/landing?locale=uk-UA', { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="hero[title]"]').fill(marker);
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form[action$="/landing/save"] button[type="submit"]:not([name])').click()]);
    await expectNoServerError(page);
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.lp-hero h1')).toHaveText(marker);

    // Reset restores the translated default.
    await page.goto('/admin/appearance/landing?locale=uk-UA', { waitUntil: 'domcontentloaded' });
    await page.locator('button[name="reset"]').click();
    await page.locator('[data-confirm-accept]').click();
    await page.waitForLoadState('domcontentloaded');
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.lp-hero h1')).not.toHaveText(marker);
  } finally {
    await setMode('hybrid');
  }
});
