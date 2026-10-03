import { expect, test, type Page } from '@playwright/test';

test.describe.configure({ retries: 0 });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/|$)/), page.locator('button[type="submit"]').click()]);
}

test('orders export to CSV and filter by a date range', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Read-only, runs once.');
  await loginAdmin(page);
  const lines = async (query: string) => {
    const response = await page.request.get(`/admin/orders/export.csv${query}`);
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('text/csv');
    return (await response.text()).replace(/^\uFEFF/, '').trim().split('\n');
  };

  const all = await lines('');
  expect(all[0]).toMatch(/^order,date,status/);
  expect(all.length).toBeGreaterThan(1);

  expect((await lines('?date_from=2999-01-01')).length).toBe(1); // header only
  expect((await lines('?date_to=2000-01-01')).length).toBe(1);
  expect((await lines('?date_from=2000-01-01&date_to=2999-12-31')).length).toBe(all.length);
  expect((await lines('?date_from=not-a-date')).length).toBe(all.length); // an invalid date is ignored

  // the list uses the same filter, and its export link carries it
  await page.goto('/admin/orders?date_from=2999-01-01', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('input[name="date_from"]')).toHaveValue('2999-01-01');
  await expect(page.locator('a[href*="/admin/orders/export.csv"]')).toHaveAttribute('href', /date_from=2999-01-01/);
  await expect(page.locator('input[name="order_ids[]"]')).toHaveCount(0);
});

test('an order note is added in place: the page is not reloaded and the note shows up', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating data runs once.');
  await loginAdmin(page);
  await page.goto('/admin/orders', { waitUntil: 'domcontentloaded' });
  const href = await page.locator('a[href^="/admin/orders/"]:not([href*="export"]):not([href*="manual"]):not([href*="preview"])').first().getAttribute('href');
  await page.goto(href!, { waitUntil: 'load' });
  await page.evaluate(() => { (window as unknown as { __kept: boolean }).__kept = true; });
  const note = `in-place note ${Date.now()}`;
  const form = page.locator('form[action$="/note"]');
  await form.locator('textarea[name="note"]').fill(note);
  await form.locator('button[type="submit"]').click();
  await expect(page.locator('main.admin-content')).toContainText(note, { timeout: 15_000 });
  expect(await page.evaluate(() => (window as unknown as { __kept?: boolean }).__kept)).toBe(true);
});
