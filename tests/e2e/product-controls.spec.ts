import { expect, test } from '@playwright/test';

test('product controls: hidden from catalog, reviews off, quantity rules and a customer-group price', async ({ browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating data runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  const suffix = `${Date.now()}-${testInfo.retry}`;
  const email = `e2e-vip-${suffix}@example.test`;
  const password = 'Nexora-Customer-2026!';
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

  const save = async (fill: Record<string, string>, checks: Record<string, boolean> = {}): Promise<string> => {
    await admin.goto(editHref!, { waitUntil: 'domcontentloaded' });
    for (const [name, value] of Object.entries(fill)) {
      await admin.locator(`#product-form [name="${name}"]`).evaluate((el: HTMLInputElement, v: string) => { el.value = v; }, value);
    }
    for (const [name, on] of Object.entries(checks)) {
      await admin.locator(`#product-form input[name="${name}"][type="checkbox"]`).evaluate((el: HTMLInputElement, v: boolean) => { el.checked = v; }, on);
    }
    await Promise.all([
      admin.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/edit')),
      admin.locator('#product-form').evaluate((form: HTMLFormElement) => form.requestSubmit()),
    ]);
    await admin.waitForLoadState('load');
    await admin.goto(editHref!, { waitUntil: 'domcontentloaded' });
    return admin.locator('input[name="slug"]').inputValue();
  };

  const guestCtx = await browser.newContext();
  const guest = await guestCtx.newPage();
  const slug = await save({}, {});
  try {
    // Hidden: gone from the catalog, still open by its link, kept out of the index.
    await save({}, { hidden: true, reviews_off: true });
    await guest.goto('/catalog?per_page=60', { waitUntil: 'domcontentloaded' });
    await expect(guest.locator(`[data-product-card][data-track-id="${sku}"]`)).toHaveCount(0);
    const response = await guest.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
    expect(response?.status()).toBe(200);
    await expect(guest.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
    await expect(guest.locator('#reviews')).toHaveCount(0);

    // Quantity rules show in the buy form.
    await save({ min_qty: '2', qty_step: '2', max_qty: '10' }, { hidden: false, reviews_off: false });
    await guest.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
    const qty = guest.locator('form[data-buy-actions] input[data-qty-input]').first();
    await expect(qty).toHaveAttribute('min', '2');
    await expect(qty).toHaveAttribute('step', '2');
    await expect(qty).toHaveAttribute('max', '10');
    await expect(guest.locator('#reviews')).toHaveCount(1);

    // A price of the customer group shows after sign-in.
    const memberCtx = await browser.newContext();
    const member = await memberCtx.newPage();
    await member.goto('/account/register', { waitUntil: 'domcontentloaded' });
    const form = member.locator('form.account-form');
    await form.locator('input[name="display_name"]').fill(`Vip ${suffix}`);
    await form.locator('input[name="email"]').fill(email);
    await form.locator('input[name="password"]').fill(password);
    await Promise.all([member.waitForURL(/\/account\/login(?:\?|$)/), form.locator('button[type="submit"]').click()]);
    await admin.goto(`/admin/commerce/customers?q=${encodeURIComponent(email)}`, { waitUntil: 'domcontentloaded' });
    const groupForm = admin.locator('form.admin-group-form').first();
    await groupForm.locator('input[name="customer_group_code"]').fill('vip');
    await Promise.all([admin.waitForLoadState('domcontentloaded'), groupForm.locator('button[type="submit"]').click()]);

    await save({ group_prices: 'vip=0.50' });
    await member.locator('input[name="_username"]').fill(email);
    await member.locator('input[name="_password"]').fill(password);
    await Promise.all([member.waitForURL(/\/account(?:\?.*)?$/), member.locator('form.account-form button[type="submit"]').click()]);
    await member.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
    await expect(member.locator('.product-price__current').first()).toContainText('0,50');
    await guest.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
    await expect(guest.locator('.product-price__current').first()).not.toContainText('0,50 ');
  } finally {
    await save({ group_prices: '', min_qty: '1', qty_step: '1', max_qty: '' }, { hidden: false, reviews_off: false });
  }
});
