import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

// Release 3.97.24: reverse charge. VIES stand-in: a number ending in 0 is valid. The shop is in Ukraine, so a Danish number is "another EU country".

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

const sql = (q: string): string => execFileSync('php', ['bin/console', 'dbal:run-sql', q], { encoding: 'utf8', env: process.env });

async function setSwitch(page: Page, on: boolean): Promise<void> {
  await page.goto('/admin/finance/vat', { waitUntil: 'domcontentloaded' });
  const form = page.locator('[data-reverse-charge-form]');
  await form.locator('input[name="reverse_charge"]').setChecked(on);
  await Promise.all([page.waitForResponse((r) => r.url().endsWith('/vat/settings') && r.request().method() === 'POST'), form.locator('button[type="submit"]').click()]);
}

async function placeOrder(page: Page, taxId: string): Promise<string> {
  await page.goto('/catalog', { waitUntil: 'networkidle' });
  const card = page.locator('[data-product-card]').filter({ has: page.locator('form[data-card-add-to-cart]') }).first();
  const added = page.waitForResponse((r) => r.url().endsWith('/cart/add') && r.request().method() === 'POST');
  await card.locator('form[data-card-add-to-cart] button[type="submit"]').click();
  expect((await (await added).json()).ok).toBe(true);
  await page.goto('/checkout', { waitUntil: 'domcontentloaded' });
  const accept = page.locator('[data-consent-accept-all]').first();
  if (await accept.isVisible().catch(() => false)) await accept.click();
  await page.locator('[data-checkout-form] input[name="name"]').fill('Business Buyer');
  await page.locator('[data-checkout-form] input[name="phone"]').fill('+380501112255');
  await page.locator('[data-checkout-block="company"] summary').click();
  await page.locator('[data-checkout-form] input[name="company_name"]').fill('ACME ApS');
  await page.locator('[data-checkout-form] input[name="company_tax_id"]').fill(taxId);
  await page.locator('.ck-choice[data-carrier="self_pickup"]').click();
  await page.locator('input[name="payment_method"][value="cash_on_delivery"]').check();
  await Promise.all([page.waitForURL(/\/checkout\/success\//, { timeout: 90_000 }), page.locator('button.place-order').click()]);

  return page.url().match(/success\/([0-9a-f-]{36})/)![1];
}

test('a valid EU VAT number takes the VAT off the order, a domestic or unknown one does not', async ({ page, browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Creates orders once.');
  test.setTimeout(420_000);
  page.setDefaultNavigationTimeout(120_000);
  await loginAdmin(page);
  try {
    await setSwitch(page, true);
    const shop = await (await browser.newContext({ baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8000' })).newPage();
    shop.setDefaultNavigationTimeout(120_000);
    await shop.goto('/checkout', { waitUntil: 'domcontentloaded' });

    const withVat = await placeOrder(shop, 'DK 1234 5670');
    const row = sql("SELECT vat_relief_minor,tax_minor,tax_calculation_mode,company_vat_status,(subtotal_minor-discount_minor+shipping_minor-total_minor) AS diff FROM mc_sales_order ORDER BY id DESC LIMIT 1");
    const lines = row.replace(/\x1b\[[0-9;]*m/g, '').split('\n').map((l) => l.trim()).filter((l) => l !== '' && !l.startsWith('---'));
    const cells = (lines[lines.length - 1] ?? '').split(/\s+/);
    expect(Number(cells[0])).toBeGreaterThan(0); // the VAT that was taken off
    expect(Number(cells[1])).toBe(0); // no VAT left in the order
    expect(cells[2]).toBe('reverse_charge');
    expect(cells[3]).toBe('valid');
    expect(Number(cells[4])).toBe(Number(cells[0])); // the total is lower by exactly that VAT

    // the invoice says so
    await page.goto('/admin/orders/' + withVat, { waitUntil: 'domcontentloaded' });
    await page.locator('form:has(input[name="type"][value="invoice"]) button').click();
    await page.waitForURL(/\/admin\/orders\/[0-9a-f-]{36}/);
    const docHref = (await page.locator('a').evaluateAll((links) => links.map((l) => (l as HTMLAnchorElement).getAttribute('href') ?? '').filter((h) => /\/admin\/orders\/documents\/[0-9a-f-]{36}$/.test(h))))[0];
    expect(docHref).toBeTruthy();
    await page.goto(docHref, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-reverse-charge]')).toBeVisible();

    // a number of the shop's own country keeps the VAT
    await placeOrder(shop, 'UA 1234 5670');
    const plain = sql('SELECT vat_relief_minor,tax_calculation_mode FROM mc_sales_order ORDER BY id DESC LIMIT 1');
    expect(plain).toContain('included');
    expect(plain).not.toContain('reverse_charge');

    // an EU number VIES rejects keeps the VAT too
    await placeOrder(shop, 'DK 1234 5679');
    expect(sql('SELECT company_vat_status,tax_calculation_mode FROM mc_sales_order ORDER BY id DESC LIMIT 1')).toContain('invalid');

    // with the switch off nothing is taken off, even for a valid number
    await setSwitch(page, false);
    await placeOrder(shop, 'DK 1234 5670');
    expect(sql('SELECT tax_calculation_mode FROM mc_sales_order ORDER BY id DESC LIMIT 1')).toContain('included');
  } finally {
    await setSwitch(page, false);
  }
});
