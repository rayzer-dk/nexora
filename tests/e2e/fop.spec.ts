import { expect, test, type Page } from '@playwright/test';

// Release 3.97.24: the FOP package — income against the limit, the book of income, the delivery note.

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

async function saveSettings(page: Page, values: { enabled: boolean; group?: string; limit?: string; warn?: string }): Promise<void> {
  await page.goto('/admin/finance/fop', { waitUntil: 'domcontentloaded' });
  const form = page.locator('form[action$="/finance/fop/save"]');
  await form.locator('input[name="enabled"]').setChecked(values.enabled);
  if (values.group) await form.locator('select[name="tax_group"]').selectOption(values.group);
  await form.locator('input[name="limit_override"]').fill(values.limit ?? '');
  if (values.warn) await form.locator('input[name="warn_percent"]').fill(values.warn);
  await Promise.all([page.waitForURL(/finance\/fop$/), form.locator('button[type="submit"]').click()]);
}

test('the FOP page shows income against the limit, warns on the dashboard and gives the book of income', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared settings once.');
  test.setTimeout(240_000);
  page.setDefaultNavigationTimeout(120_000);
  await loginAdmin(page);

  try {
    // a very small own limit: the income of the year is over it
    await saveSettings(page, { enabled: true, group: '3', limit: '1' });
    const summary = page.locator('[data-fop-summary]');
    await expect(summary).toHaveAttribute('data-status', 'over');
    await expect(summary.locator('[data-fop-percent]')).toContainText('%');
    await expect(summary.locator('tbody tr')).toHaveCount(16); // 12 months + 4 quarters

    // the dashboard carries the warning with a link
    await page.goto('/admin', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-fop-alert="over"]')).toBeVisible();

    // a huge limit: no warning
    await saveSettings(page, { enabled: true, group: '3', limit: '999999999' });
    await expect(page.locator('[data-fop-summary]')).toHaveAttribute('data-status', /ok|forecast/);
    await page.goto('/admin', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-fop-alert="over"]')).toHaveCount(0);

    // the book of income: a CSV that opens in Excel, with a total line
    await page.goto('/admin/finance/fop', { waitUntil: 'domcontentloaded' });
    const year = new Date().getUTCFullYear();
    const res = await page.request.get(`/admin/finance/fop/income-book.csv?from=${year}-01-01&to=${year}-12-31`);
    expect(res.ok()).toBe(true);
    expect(res.headers()['content-type']).toContain('text/csv');
    const body = (await res.body()).toString('utf8');
    expect(body.charCodeAt(0)).toBe(0xfeff);
    const lines = body.replace(/^\uFEFF/, '').trim().split('\n');
    expect(lines.length).toBeGreaterThanOrEqual(2);
    expect(lines[0].split(';').length).toBe(6);
    expect(lines[lines.length - 1]).toMatch(/;\d+\.\d{2};$/);
    expect((await page.request.get('/admin/finance/fop/income-book.csv?from=2020-01-01&to=2030-01-01')).status()).toBe(404);
  } finally {
    await saveSettings(page, { enabled: false });
  }
});

test('a delivery note is issued for an order and numbered', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Issues a document once.');
  test.setTimeout(240_000);
  page.setDefaultNavigationTimeout(120_000);
  await loginAdmin(page);
  await page.goto('/admin/orders', { waitUntil: 'domcontentloaded' });
  const link = page.locator('tbody tr a[href*="/admin/orders/"]').first();
  await Promise.all([page.waitForURL(/\/admin\/orders\/[0-9a-f-]{36}/), link.click()]);
  await page.locator('form:has(input[name="type"][value="delivery_note"]) button').click();
  await page.waitForURL(/\/admin\/orders\/[0-9a-f-]{36}/);
  await expect(page.locator('body')).toContainText(/VN-\d{4}-\d{6}/);
});
