import { expect, test } from '@playwright/test';

test('with stock tracking on a customer cannot order more than is in stock; with it off the product is always available', async ({ browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating data runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  const sku = 'DEMO-042';
  const adminCtx = await browser.newContext();
  const admin = await adminCtx.newPage();
  await admin.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await admin.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await admin.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([admin.waitForURL(/\/admin(?:\/(?!login)|$)/), admin.locator('button[type="submit"]').click()]);
  await admin.goto('/admin/catalog/products?per_page=200', { waitUntil: 'domcontentloaded' });
  const editHref = await admin.locator('table.admin-table tbody tr').filter({ hasText: sku }).first().locator('a[href$="/edit"]').first().getAttribute('href');
  expect(editHref).toBeTruthy();

  await admin.goto(editHref!, { waitUntil: 'domcontentloaded' });
  const originalStock = await admin.locator('#product-form input[name="stock_quantity"]').inputValue();
  const save = async (stock: string, track: boolean): Promise<string> => {
    await admin.goto(editHref!, { waitUntil: 'domcontentloaded' });
    await admin.locator('#product-form input[name="stock_quantity"]').evaluate((el: HTMLInputElement, v: string) => { el.value = v; }, stock);
    await admin.locator('#product-form input[name="track_stock"]').evaluate((el: HTMLInputElement, v: boolean) => { el.checked = v; }, track);
    await Promise.all([
      admin.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/edit')),
      admin.locator('#product-form').evaluate((form: HTMLFormElement) => form.requestSubmit()),
    ]);
    await admin.waitForLoadState('load');
    await admin.goto(editHref!, { waitUntil: 'domcontentloaded' });
    return admin.locator('input[name="slug"]').inputValue();
  };

  const tryAdd = async (slug: string, quantity: string): Promise<boolean> => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
    const form = page.locator('form[data-buy-actions]').first();
    await form.locator('input[data-qty-input]').evaluate((el: HTMLInputElement, v: string) => { el.removeAttribute('max'); el.value = v; el.dispatchEvent(new Event('change', { bubbles: true })); }, quantity);
    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith('/cart/add') && r.request().method() === 'POST'),
      form.locator('button[type="submit"]').first().click(),
    ]);
    const ok = response.status() < 400 && ((await response.json().catch(() => ({ ok: false }))) as { ok?: boolean }).ok === true;
    await ctx.close();
    return ok;
  };

  try {
    const slug = await save('1', true);
    expect(await tryAdd(slug, '3'), 'ordering more than the stock must be refused').toBe(false);
    await save('1', false);
    expect(await tryAdd(slug, '3'), 'an untracked product must stay orderable').toBe(true);
  } finally {
    await save(originalStock.replace(/\.?0+$/, '') || '0', true);
  }
});
