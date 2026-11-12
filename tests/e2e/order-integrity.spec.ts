import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError, loginAdmin } from './helpers';

test.describe.configure({ mode: 'serial', retries: 0 });

test.beforeEach(({}, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Places real orders, runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
});

async function declineCookies(page: Page): Promise<void> {
  await page.getByRole('button', { name: /Відхилити/ }).click({ timeout: 2000 }).catch(() => undefined);
}

async function addProductFromSearch(page: Page, name: string): Promise<void> {
  await page.goto('/catalog?q=' + encodeURIComponent(name), { waitUntil: 'domcontentloaded' });
  await declineCookies(page);
  const href = await page.locator('.catalog-card a[href^="/"]').first().getAttribute('href');
  await page.goto(href!, { waitUntil: 'domcontentloaded' });
  await Promise.all([page.waitForResponse((r) => r.url().includes('/cart/add')), page.locator('form.buy-actions button[type="submit"]').first().click()]);
}


async function fillCheckout(page: Page): Promise<void> {
  await page.goto('/checkout', { waitUntil: 'domcontentloaded' });
  await declineCookies(page);
  await page.locator('[data-checkout-form] input[name="name"]').fill('Тест Цілісність');
  await page.locator('[data-checkout-form] input[name="phone"]').fill('+380501112299');
  await page.locator('.ck-choice[data-carrier="self_pickup"]').click();
  await page.locator('input[name="payment_method"][value="cash_on_delivery"]').check();
}

test('the same checkout form sent twice at once creates one order', async ({ page }) => {
  await addProductFromSearch(page, 'Beats');
  await fillCheckout(page);
  await page.waitForTimeout(3000); // the form refuses a submit that is faster than a person
  const data = await page.evaluate(() => {
    const out: Record<string, string> = {};
    new FormData(document.querySelector('[data-checkout-form]') as HTMLFormElement).forEach((v, k) => { if (typeof v === 'string') out[k] = v; });
    return out;
  });
  const send = () => page.request.post('/checkout/place', { form: data, maxRedirects: 0 });
  const [a, b] = await Promise.all([send(), send()]);

  expect([a.status(), b.status()], 'neither request may fail with a server error').toEqual([302, 302]);
  const where = [a.headers()['location'], b.headers()['location']];
  expect(where[0]).toMatch(/\/checkout\/success\//);
  expect(where[1], 'both answers point at the same order').toBe(where[0]);
  // a third, later repeat of the same form is answered with the same order too
  const again = await send();
  expect(again.headers()['location']).toBe(where[0]);
});

test('an order keeps its price and total when the product price changes afterwards', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  const row = page.locator('table.admin-table tbody tr').first();
  const name = (await row.locator('a').first().innerText()).trim();
  const priceInput = row.locator('[data-quick-price] input');
  const oldPrice = await priceInput.inputValue();

  const shop = await page.context().browser()!.newContext({ baseURL: page.url().split('/admin')[0], locale: 'uk-UA' });
  const buyer = await shop.newPage();
  await addProductFromSearch(buyer, name);
  await fillCheckout(buyer);
  await Promise.all([buyer.waitForURL(/\/checkout\/success\//, { timeout: 30_000 }), buyer.locator('button.place-order').click()]);
  await expectNoServerError(buyer);
  const successUrl = buyer.url();
  const totalBefore = (await buyer.locator('.ck-success__items .is-total b').innerText()).trim();
  const number = (await buyer.locator('.ck-success__number strong').innerText()).trim();

  await page.goto('/admin/orders', { waitUntil: 'domcontentloaded' });
  const orderRow = page.locator('table.admin-table tbody tr', { hasText: number }).first();
  const orderRowBefore = (await orderRow.innerText()).replace(/\s+/g, ' ');

  try {
    await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
    const changed = page.locator('table.admin-table tbody tr').first().locator('[data-quick-price] input');
    await changed.fill((Number(oldPrice.replace(',', '.')) + 37.5).toFixed(2));
    const saved = page.waitForResponse((r) => r.url().includes('/quick-price'));
    await changed.press('Enter');
    expect((await saved).status()).toBe(200);

    await buyer.goto(successUrl, { waitUntil: 'domcontentloaded' });
    expect((await buyer.locator('.ck-success__items .is-total b').innerText()).trim(), 'the buyer still sees the total he paid').toBe(totalBefore);
    await page.goto('/admin/orders', { waitUntil: 'domcontentloaded' });
    expect((await page.locator('table.admin-table tbody tr', { hasText: number }).first().innerText()).replace(/\s+/g, ' '), 'the order list shows the same amount').toBe(orderRowBefore);
  } finally {
    await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
    const restore = page.locator('table.admin-table tbody tr').first().locator('[data-quick-price] input');
    await restore.fill(oldPrice);
    await Promise.all([page.waitForResponse((r) => r.url().includes('/quick-price')), restore.press('Enter')]);
    await shop.close();
  }
});
