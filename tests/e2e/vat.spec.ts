import { expect, test } from '@playwright/test';
import { loginAdmin } from './helpers';

// Release 3.97.24: VAT by country per quarter and a VIES check. Needs the stand-in on :8099 and VIES_API_BASE=http://127.0.0.1:8099/vies.

test('VAT by country is listed per quarter with a CSV, and VIES answers are shown', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'One browser is enough.');
  test.setTimeout(240_000);
  page.setDefaultNavigationTimeout(120_000);
  await loginAdmin(page);

  // the report: the shop's orders are in the current quarter, taxed in the shop's own country
  await page.goto('/admin/finance/vat', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('h1')).toBeVisible();
  const rows = page.locator('[data-vat-report] tbody tr');
  expect(await rows.count()).toBeGreaterThan(0);
  const year = new Date().getUTCFullYear();
  const quarter = Math.floor(new Date().getUTCMonth() / 3) + 1;
  const csv = await page.request.get(`/admin/finance/vat/report.csv?year=${year}&quarter=${quarter}`);
  expect(csv.ok()).toBe(true);
  const text = (await csv.body()).toString('utf8').replace(/^\uFEFF/, '').trim().split('\n');
  expect(text[0].split(';').length).toBe(7);
  expect(text.length).toBeGreaterThanOrEqual(2);
  // a quarter without orders says so
  await page.goto('/admin/finance/vat?year=' + (year - 4) + '&quarter=1', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-vat-report]')).toHaveCount(0);

  // VIES: a valid number (ends in 0), an invalid one (ends in 9), an unavailable service (anything else), a number that is not even a number
  const ask = async (country: string, number: string): Promise<void> => {
    await page.goto('/admin/finance/vat', { waitUntil: 'domcontentloaded' });
    const form = page.locator('[data-vies-form]');
    await form.locator('select[name="country"]').selectOption(country);
    await form.locator('input[name="number"]').fill(number);
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), form.locator('button[type="submit"]').click()]);
  };
  await ask('DK', 'DK 1234 5670');
  await expect(page.locator('[data-vies-result="VALID"]')).toContainText('ACME APS');
  await ask('DE', '123456789');
  await expect(page.locator('[data-vies-result="INVALID"]')).toBeVisible();
  await ask('FR', '123456781');
  await expect(page.locator('[data-vies-result="SERVICE_UNAVAILABLE"]')).toBeVisible();
  await ask('DK', '../x');
  await expect(page.locator('[data-vies-result="INVALID_INPUT"]')).toBeVisible();
});
