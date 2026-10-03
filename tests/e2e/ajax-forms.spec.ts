import { expect, test, type Page } from '@playwright/test';

test.describe.configure({ retries: 0 });

async function acceptCookies(page: Page): Promise<void> {
  const accept = page.locator('[data-consent-accept-all], [data-cookie-accept], .consent-banner button').first();
  if (await accept.isVisible().catch(() => false)) await accept.click().catch(() => undefined);
}

const keep = (page: Page) => page.evaluate(() => { (window as unknown as { __kept: boolean }).__kept = true; });
const kept = (page: Page) => page.evaluate(() => (window as unknown as { __kept?: boolean }).__kept === true);

test('newsletter sign-up and "add to comparison" answer with a toast and never reload the page', async ({ page }) => {
  await page.goto('/catalog', { waitUntil: 'load' });
  await acceptCookies(page);
  const url = page.url();
  await keep(page);

  const compare = page.locator('.catalog-card__compare').first();
  await compare.locator('button[type="submit"]').first().click({ force: true });
  await expect(page.locator('.storefront-toast').first()).toBeVisible({ timeout: 10_000 });
  expect(page.url()).toBe(url);
  expect(await kept(page)).toBe(true);

  const email = page.locator('.newsletter-signup__form input[type="email"]').first();
  await email.scrollIntoViewIfNeeded();
  await email.fill(`ajax-${Date.now()}@e2e.test`);
  await page.waitForTimeout(1500); // the spam guard refuses a form sent within a second of rendering
  await page.locator('.newsletter-signup__form button[type="submit"]').first().click();
  await expect(email).toHaveValue('');
  expect(page.url()).toBe(url);
  expect(await kept(page)).toBe(true);
});
