import { expect, test } from '@playwright/test';

test('a rejected checkout keeps everything the buyer typed or chose', async ({ page }) => {
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const card = page.locator('[data-product-card]').filter({ has: page.locator('form[data-card-add-to-cart]') }).first();
  const added = page.waitForResponse((r) => r.url().endsWith('/cart/add') && r.request().method() === 'POST');
  await card.locator('form[data-card-add-to-cart] button[type="submit"]').click();
  await added;

  await page.goto('/checkout', { waitUntil: 'domcontentloaded' });
  const form = page.locator('[data-checkout-form]');
  await form.locator('[name="name"]').fill('Іван Тест');
  await form.locator('[name="phone"]').fill('12'); // invalid on purpose
  const email = form.locator('[name="email"]');
  if (await email.count()) await email.fill('ivan@example.test');
  const comment = form.locator('[name="customer_comment"]');
  if (await comment.count()) {
    await form.locator('[data-checkout-block="comment"] summary').click();
    await comment.fill('Подзвоніть перед доставкою');
  }
  const city = page.locator('[data-delivery-city]');
  if (await city.count()) {
    await city.fill('Київ');
    await page.locator('[data-delivery-manual-toggle]').click();
    await page.locator('[name="delivery_manual"]').fill('Київ, відділення 1');
  }
  const payments = form.locator('input[name="payment_method"]');
  const lastPayment = (await payments.count()) > 1 ? await payments.last().getAttribute('value') : null;
  if (lastPayment) await form.locator(`input[name="payment_method"][value="${lastPayment}"]`).check({ force: true });

  await page.locator('[data-place-order]').first().click();
  await page.waitForURL(/\/checkout(?:\?|$)/);
  await expect(page.locator('.store-notice, [role="alert"]').first()).toBeVisible();
  await expect(form.locator('[name="name"]')).toHaveValue('Іван Тест');
  await expect(form.locator('[name="phone"]')).toHaveValue('12');
  if (await email.count()) await expect(form.locator('[name="email"]')).toHaveValue('ivan@example.test');
  if (await comment.count()) await expect(form.locator('[name="customer_comment"]')).toHaveValue('Подзвоніть перед доставкою');
  if (await city.count()) await expect(page.locator('[name="delivery_manual"]')).toHaveValue('Київ, відділення 1');
  if (lastPayment) await expect(form.locator(`input[name="payment_method"][value="${lastPayment}"]`)).toBeChecked();
});
