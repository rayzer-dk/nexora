import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

async function addFirstProduct(page: Page): Promise<void> {
  await page.goto('/catalog', { waitUntil: 'networkidle' }); // scripts must be attached before the AJAX add
  const card = page.locator('[data-product-card]').filter({ has: page.locator('form[data-card-add-to-cart]') }).first();
  const added = page.waitForResponse((r) => r.url().endsWith('/cart/add') && r.request().method() === 'POST');
  await card.locator('form[data-card-add-to-cart] button[type="submit"]').click();
  expect((await (await added).json()).ok).toBe(true);
}

async function acceptCookies(page: Page): Promise<void> {
  const accept = page.locator('[data-consent-accept-all]').first();
  if (await accept.isVisible().catch(() => false)) await accept.click();
}

test('the header cart badge updates at once and survives a reload', async ({ page }) => {
  await addFirstProduct(page);
  await expect(page.locator('.reference-cart-count')).toHaveText('1');
  await expect(page.locator('.reference-cart-count')).toBeVisible();
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.reference-cart-count')).toBeVisible();
  await expect(page.locator('.reference-cart-count')).toHaveText('1');
});

test('the header cart icon opens a slide-in drawer with photos, stepper and checkout button', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await addFirstProduct(page);
  const drawer = page.locator('.cart-drawer.is-open');
  await expect(drawer).toBeVisible(); // auto-opens after add on desktop
  await expect(drawer.locator('.drawer-line__img img').first()).toBeVisible();
  await expect(drawer.locator('a[href="/checkout"]')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.locator('.cart-drawer.is-open')).toHaveCount(0);

  await page.locator('[data-cart-open]').click();
  await expect(page.locator('.cart-drawer.is-open')).toBeVisible();
  const qty = page.locator('.cart-drawer [data-drawer-qty]').first();
  await page.locator('.cart-drawer [data-drawer-plus]').first().click();
  await expect(qty).toHaveValue('2');
  await page.locator('.cart-drawer [data-drawer-remove] button').first().click();
  await expect(page.locator('.cart-drawer__empty')).toBeVisible();
  await expect(page.locator('.reference-cart-count')).toBeHidden();
});

test('checkout: validation is visible and pickup needs no city; order is placed with pickup and pay-on-receipt', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await addFirstProduct(page);
  await page.goto('/checkout', { waitUntil: 'domcontentloaded' });
  await acceptCookies(page);
  const submit = page.locator('button.place-order');
  await expect(submit).toBeVisible();
  await expect(page.locator('.summary-image').first()).toBeVisible();

  await submit.click();
  await expect(page.locator('[data-checkout-errors]')).toBeVisible();
  await expect(page.locator('[data-checkout-form] input[name="name"]')).toHaveAttribute('aria-invalid', 'true');

  await page.locator('[data-checkout-form] input[name="name"]').fill('Тест Самовивіз');
  await page.locator('[data-checkout-form] input[name="phone"]').fill('+380501112233');
  await page.locator('.ck-choice[data-carrier="self_pickup"]').click();
  await expect(page.locator('[data-delivery-pickup]')).toBeVisible();
  await expect(page.locator('[data-delivery-remote]')).toBeHidden();
  await page.locator('input[name="payment_method"][value="cash_on_delivery"]').check();
  await Promise.all([page.waitForURL(/\/checkout\/success\//, { timeout: 20_000 }), submit.click()]);
  await expectNoServerError(page);
  await expect(page.locator('.ck-success__box').first()).toContainText(/.+/);
  await expect(page.locator('.ck-success__number strong')).toBeVisible();
});

test('checkout: manual address works when the carrier directory is unavailable', async ({ page }) => {
  await addFirstProduct(page);
  await page.goto('/checkout', { waitUntil: 'domcontentloaded' });
  await acceptCookies(page);
  await page.locator('[data-checkout-form] input[name="name"]').fill('Тест Вручну');
  await page.locator('[data-checkout-form] input[name="phone"]').fill('+380501112244');
  await page.locator('[data-delivery-city]').fill('Львів');
  await page.locator('[data-delivery-manual-toggle]').click();
  await page.locator('[name="delivery_manual"]').fill('Львів, відділення 5');
  const region = page.locator('select[name="delivery_region"]');
  if (await region.count()) await region.selectOption({ index: 1 });
  await Promise.all([page.waitForURL(/\/checkout\/success\//, { timeout: 20_000 }), page.locator('button.place-order').click()]);
  await expectNoServerError(page);
});

test('checkout is usable on a phone: sticky submit bar is visible without scrolling', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 800 });
  await addFirstProduct(page);
  await page.goto('/checkout', { waitUntil: 'domcontentloaded' });
  await acceptCookies(page);
  const bar = page.locator('.checkout-bar');
  await expect(bar).toBeInViewport();
  await expect(page.locator('button.place-order')).toBeInViewport();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
});

test('admin can switch pay-on-receipt off and the checkout stops offering it', async ({ browser }) => {
  const admin = await (await browser.newContext()).newPage();
  await admin.goto('/admin/login');
  await admin.locator('input[type="email"]').first().fill(process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.test');
  await admin.locator('input[type="password"]').first().fill(process.env.E2E_ADMIN_PASSWORD ?? '');
  await admin.locator('button[type="submit"]').first().click();
  await admin.waitForLoadState('networkidle');
  const toggle = async (on: boolean) => {
    await admin.goto('/admin/shipments/methods', { waitUntil: 'networkidle' });
    const box = admin.locator('input[name="methods[]"][value="cash_on_delivery"]');
    if (on) await box.check(); else await box.uncheck();
    await Promise.all([admin.waitForURL(/\/admin\/shipments\/methods/), admin.locator('form[action$="/methods/save"] button[type="submit"]').click()]);
  };
  await toggle(false);
  try {
    const shopper = await (await browser.newContext()).newPage();
    await addFirstProduct(shopper);
    await shopper.goto('/checkout', { waitUntil: 'domcontentloaded' });
    await expect(shopper.locator('input[name="payment_method"][value="cash_on_delivery"]')).toHaveCount(0);
    await expect(shopper.locator('input[name="payment_method"]').first()).toBeChecked();
  } finally {
    await toggle(true);
  }
});
