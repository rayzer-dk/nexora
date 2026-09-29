import { expect, test } from '@playwright/test';
import { expectNoHorizontalOverflow, expectNoServerError } from './helpers';

const legalPages: Array<{ path: string; minLength: number; headings: number }> = [
  { path: '/privacy-policy', minLength: 1500, headings: 8 },
  { path: '/cookie-policy', minLength: 1200, headings: 4 },
  { path: '/terms-and-conditions', minLength: 1500, headings: 10 },
  { path: '/returns', minLength: 900, headings: 4 },
  { path: '/shipping', minLength: 800, headings: 3 },
  { path: '/payment', minLength: 700, headings: 3 },
  { path: '/warranty', minLength: 700, headings: 3 },
  { path: '/faq', minLength: 900, headings: 3 },
  { path: '/about-us', minLength: 500, headings: 2 },
  { path: '/contact', minLength: 400, headings: 2 },
];

for (const { path, minLength, headings } of legalPages) {
  test(`${path} is a complete published information page`, async ({ page }) => {
    const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
    expect(response?.status()).toBe(200);
    await expectNoServerError(page);
    await expectNoHorizontalOverflow(page);
    const body = page.locator('.rich-content');
    await expect(body).toBeVisible();
    expect((await body.innerText()).length).toBeGreaterThan(minLength);
    expect(await body.locator('h2').count()).toBeGreaterThanOrEqual(headings);
  });
}

test('cookie policy documents the cookies the storefront really sets and the footer reopens consent', async ({ page }) => {
  await page.goto('/cookie-policy', { waitUntil: 'domcontentloaded' });
  const table = page.locator('.rich-content table');
  await expect(table).toBeVisible();
  for (const name of ['PHPSESSID', 'mc_cart', 'store_locale', 'mc_consent_v1']) {
    await expect(table).toContainText(name);
  }
  await page.locator('[data-consent-open]').first().click();
  await expect(page.locator('[data-commerce-consent]')).toBeVisible();
});

test('product page lists curated technical specifications', async ({ page }) => {
  await page.goto('/iphone-13-pro', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const text = await page.locator('main').innerText();
  for (const label of ['Дисплей', 'Процесор', 'Камера']) {
    expect(text).toContain(label);
  }
  expect(text).toContain('Apple A15 Bionic');
});
