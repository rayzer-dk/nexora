import { expect, test } from '@playwright/test';

test('a group discount shows in prices for the signed-in member and is taken off in the cart', async ({ browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating data runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  const suffix = `${Date.now()}-${testInfo.retry}`;
  const code = `g${Date.now().toString(36)}`;
  const email = `e2e-group-${suffix}@example.test`;
  const password = 'Nexora-Customer-2026!';

  const adminCtx = await browser.newContext();
  const admin = await adminCtx.newPage();
  await admin.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await admin.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await admin.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([admin.waitForURL(/\/admin(?:\/(?!login)|$)/), admin.locator('button[type="submit"]').click()]);

  await admin.goto('/admin/commerce/customer-groups', { waitUntil: 'domcontentloaded' });
  const add = admin.locator('section.admin-panel form:has(input[name="code"]:not([type="hidden"]))').last();
  await add.locator('input[name="code"]').fill(code);
  await add.locator('input[name="name"]').fill(`Group ${suffix}`);
  await add.locator('input[name="discount_percent"]').fill('10');
  await Promise.all([admin.waitForLoadState('domcontentloaded'), add.locator('button[type="submit"]').click()]);
  await expect(admin.locator(`code:text-is("${code}")`)).toBeVisible();

  const memberCtx = await browser.newContext();
  const member = await memberCtx.newPage();
  await member.goto('/account/register', { waitUntil: 'domcontentloaded' });
  const registerForm = member.locator('form.account-form');
  await registerForm.locator('input[name="display_name"]').fill(`Member ${suffix}`);
  await registerForm.locator('input[name="email"]').fill(email);
  await registerForm.locator('input[name="password"]').fill(password);
  await Promise.all([member.waitForURL(/\/account\/login(?:\?|$)/), registerForm.locator('button[type="submit"]').click()]);

  await admin.goto(`/admin/commerce/customers?q=${encodeURIComponent(email)}`, { waitUntil: 'domcontentloaded' });
  const groupForm = admin.locator('form.admin-group-form').first();
  await groupForm.locator('input[name="customer_group_code"]').fill(code);
  await Promise.all([admin.waitForLoadState('domcontentloaded'), groupForm.locator('button[type="submit"]').click()]);

  // The same product, before and after signing in.
  const guestCtx = await browser.newContext();
  const guest = await guestCtx.newPage();
  await guest.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const href = await guest.locator('.product-grid article a[href]').first().getAttribute('href');
  const path = href!;
  await guest.goto(path, { waitUntil: 'domcontentloaded' });
  const guestPrice = await guest.locator('.product-price__current').first().innerText();

  await member.locator('input[name="_username"]').fill(email);
  await member.locator('input[name="_password"]').fill(password);
  await Promise.all([member.waitForURL(/\/account(?:\?.*)?$/), member.locator('form.account-form button[type="submit"]').click()]);
  await member.goto(path, { waitUntil: 'domcontentloaded' });
  const memberPrice = await member.locator('.product-price__current').first().innerText();
  expect(memberPrice).not.toBe(guestPrice);
  await expect(member.locator('.product-price__old').first()).toContainText(guestPrice.replace(/\s/g, ' ').trim().slice(0, 3));

  // The cart takes the group discount off the order.
  await member.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const card = member.locator('[data-product-card]').filter({ has: member.locator('form[data-card-add-to-cart]') }).first();
  await Promise.all([
    member.waitForResponse((r) => r.url().endsWith('/cart/add') && r.request().method() === 'POST'),
    card.locator('form[data-card-add-to-cart] button[type="submit"]').click(),
  ]);
  await member.goto('/cart', { waitUntil: 'domcontentloaded' });
  await expect(member.locator('main .is-discount, main [data-cart-discount]').first()).toBeVisible();
});
