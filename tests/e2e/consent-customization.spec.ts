import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL(/\/admin(?:\/(?!login)|$)/),
    page.locator('button[type="submit"]').click(),
  ]);
}

test.describe.configure({ retries: 0, mode: 'serial' });

async function saveConsent(page: Page, values: { title: string; text: string; position: string; tone: string; icon: boolean }) {
  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const panel = page.locator('[data-consent-settings]');
  await panel.locator('input[name^="consent_title["]').first().fill(values.title);
  await panel.locator('textarea[name^="consent_text["]').first().fill(values.text);
  await panel.locator(`input[name="consent_position"][value="${values.position}"]`).check({ force: true });
  await panel.locator(`input[name="consent_tone"][value="${values.tone}"]`).check({ force: true });
  await panel.locator('input[name="consent_show_icon"]').setChecked(values.icon);
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/admin/appearance/storefront')),
    page.locator('form[data-dirty-guard] button[type="submit"]').last().click(),
  ]);
}

test('the cookie notice text, position, tone and icon are set in Appearance and applied on the storefront', async ({ page, browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings run once.');
  await loginAdmin(page);
  try {
    await saveConsent(page, { title: 'E2E cookie title', text: '<b>E2E</b> cookie text', position: 'card_right', tone: 'dark', icon: false });
    const ctx = await browser.newContext({ baseURL: testInfo.project.use.baseURL, locale: 'uk-UA' });
    const shop = await ctx.newPage();
    await shop.goto('/', { waitUntil: 'domcontentloaded' });
    const banner = shop.locator('[data-commerce-consent]');
    await expect(banner).toBeVisible();
    await expect(banner).toHaveClass(/mc-consent--card_right/);
    await expect(banner).toHaveClass(/mc-consent--dark/);
    await expect(banner.locator('.mc-consent__title')).toHaveText('E2E cookie title');
    await expect(banner.locator('.mc-consent__text')).toHaveText('E2E cookie text');
    await expect(banner.locator('.mc-consent__icon')).toHaveCount(0);
    await ctx.close();
  } finally {
    await saveConsent(page, { title: '', text: '', position: 'bar', tone: 'light', icon: true });
  }
  const ctx = await browser.newContext({ baseURL: testInfo.project.use.baseURL, locale: 'uk-UA' });
  const shop = await ctx.newPage();
  await shop.goto('/', { waitUntil: 'domcontentloaded' });
  await expect(shop.locator('.mc-consent__title')).not.toHaveText('E2E cookie title');
  await expect(shop.locator('.mc-consent__icon')).toHaveCount(1);
  await ctx.close();
});
