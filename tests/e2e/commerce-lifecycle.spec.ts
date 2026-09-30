import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { expectNoServerError } from './helpers';

test('catalog to cart, registration, checkout and forum topic lifecycle', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Full mutating commerce lifecycle runs once per CI database.');

  const suffix = `${Date.now()}-${testInfo.retry}`;
  const email = `e2e-customer-${suffix}@example.test`;
  const password = 'Nexora-Customer-2026!';
  const displayName = `E2E Customer ${suffix}`;
  const forumTopicTitle = `E2E forum topic ${suffix}`;

  const catalogResponse = await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  expect(catalogResponse?.status() ?? 0).toBeLessThan(500);
  await expectNoServerError(page);

  const purchasableCard = page.locator('[data-product-card]').filter({
    has: page.locator('form[data-card-add-to-cart]'),
  }).first();
  await expect(purchasableCard).toBeVisible();
  const productName = (await purchasableCard.locator('h2 a').innerText()).trim();
  const productUrl = await purchasableCard.locator('h2 a').getAttribute('href');
  expect(productName).not.toBe('');
  expect(productUrl).toBeTruthy();

  await page.goto(productUrl!, { waitUntil: 'domcontentloaded' });
  const buyForm = page.locator('form[data-buy-actions]');
  await expect(buyForm).toBeVisible();
  const addResponsePromise = page.waitForResponse((response) =>
    response.url().endsWith('/cart/add') && response.request().method() === 'POST'
  );
  await buyForm.locator('[data-primary-buy]').click();
  const addResponse = await addResponsePromise;
  expect(addResponse.status()).toBeLessThan(500);
  const addPayload = await addResponse.json();
  expect(addPayload.ok).toBe(true);
  expect(Number(addPayload.cart?.count ?? 0)).toBeGreaterThan(0);

  await page.goto('/cart', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('body')).toContainText(productName);

  await page.goto('/account/register', { waitUntil: 'domcontentloaded' });
  const registerForm = page.locator('form.account-form');
  await registerForm.locator('input[name="display_name"]').fill(displayName);
  await registerForm.locator('input[name="email"]').fill(email);
  await registerForm.locator('input[name="password"]').fill(password);
  await Promise.all([
    page.waitForURL(/\/account\/login(?:\?|$)/),
    registerForm.locator('button[type="submit"]').click(),
  ]);

  await page.locator('input[name="_username"]').fill(email);
  await page.locator('input[name="_password"]').fill(password);
  await Promise.all([
    page.waitForURL(/\/account(?:\?.*)?$/),
    page.locator('form.account-form button[type="submit"]').click(),
  ]);
  await expectNoServerError(page);

  await page.goto('/account/verification', { waitUntil: 'domcontentloaded' });
  const requestForm = page.locator('form[action="/account/verification/request"]');
  if (await requestForm.count()) {
    await requestForm.locator('input[name="channel"][value="email"]').check().catch(() => {});
    await requestForm.locator('button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');
  }

  const verificationCode = execFileSync('php', ['tests/e2e/read-verification-code.php', email], {
    encoding: 'utf8',
    env: process.env,
  }).trim();
  expect(verificationCode).toMatch(/^\d{6}$/);
  const confirmForm = page.locator('form[action="/account/verification/confirm"]');
  await confirmForm.locator('select[name="channel"]').selectOption('email');
  await confirmForm.locator('input[name="code"]').fill(verificationCode);
  await Promise.all([
    page.waitForURL(/\/account\/verification/),
    confirmForm.locator('button[type="submit"]').click(),
  ]);

  await page.goto('/cart', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('body')).toContainText(productName);

  await page.goto('/checkout', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-checkout-form]')).toBeVisible();
  await page.locator('[name="name"]').fill(displayName);
  await page.locator('[name="phone"]').fill('+380501234567');
  const checkoutForm = page.locator('[data-checkout-form]');
  const checkoutEmail = checkoutForm.locator('[name="email"]');
  if (await checkoutEmail.count()) await checkoutEmail.fill(email);

  const city = page.locator('[data-delivery-city]');
  if (await city.count()) {
    await city.fill('Київ');
    const manual = page.locator('[name="delivery_manual"]');
    if (await manual.count()) {
      await manual.evaluate((element) => {
        const input = element as HTMLInputElement;
        input.value = 'Київ, тестове відділення 1';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
      });
    }
  }

  const deliveryRegion = page.locator('select[name="delivery_region"]');
  if (await deliveryRegion.count()) await deliveryRegion.selectOption({ index: 1 });

  const cod = page.locator('input[name="payment_method"][value="cash_on_delivery"]');
  const bank = page.locator('input[name="payment_method"][value="bank_transfer"]');
  if (await cod.count()) await cod.check();
  else if (await bank.count()) await bank.check();

  await Promise.all([
    page.waitForURL(/\/checkout\/success\//, { timeout: 20_000 }),
    page.locator('button.place-order').click(),
  ]);
  await expectNoServerError(page);

  await page.goto('/forum/general', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-forum-compose-open]')).toBeVisible();
  await page.locator('[data-forum-compose-open]').click();
  const topicForm = page.locator('form[action="/forum/general/topics"]');
  await expect(topicForm).toBeVisible();
  await topicForm.locator('input[name="title"]').fill(forumTopicTitle);
  await topicForm.locator('textarea[name="body"]').fill('Автоматичний E2E тест створення теми форуму після реєстрації та підтвердження клієнта.');
  await page.waitForTimeout(1100);
  const topicResponsePromise = page.waitForResponse((response) =>
    response.url().includes('/forum/general/topics') && response.request().method() === 'POST'
  );
  await topicForm.locator('button[type="submit"]').click();
  const topicResponse = await topicResponsePromise;
  expect(topicResponse.status()).toBeLessThan(500);
  await page.waitForLoadState('domcontentloaded');
  await expectNoServerError(page);
  await expect(page.locator('.store-notice.is-success')).toBeVisible();

  await page.goto('/forum/general', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.forum-topic-row').filter({ hasText: forumTopicTitle })).toHaveCount(0);

  if (process.env.E2E_ADMIN_EMAIL && process.env.E2E_ADMIN_PASSWORD) {
    await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL);
    await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD);
    await Promise.all([
      page.waitForURL(/\/admin(?:\/|$)/),
      page.locator('button[type="submit"]').click(),
    ]);

    await page.goto('/admin/forum', { waitUntil: 'domcontentloaded' });
    await page.locator('.admin-tabs .admin-tab').nth(1).click();
    const pending = page.locator('table.admin-table tbody tr').filter({ hasText: forumTopicTitle }).first();
    await expect(pending).toBeVisible();
    const approve = pending.locator('form[action$="/approve"]');
    const approveResponsePromise = page.waitForResponse((response) =>
      response.url().includes('/admin/forum/topics/') && response.url().endsWith('/approve') && response.request().method() === 'POST'
    );
    await approve.locator('button[type="submit"]').click();
    expect((await approveResponsePromise).status()).toBeLessThan(400);
    await page.waitForLoadState('domcontentloaded');

    await page.goto('/forum/general', { waitUntil: 'domcontentloaded' });
    const publicTopic = page.locator('.forum-topic-row').filter({ hasText: forumTopicTitle });
    await expect(publicTopic).toBeVisible();
    await expect(publicTopic.locator('.forum-lock')).toHaveCount(0);

    await page.goto('/admin/forum', { waitUntil: 'domcontentloaded' });
    await page.locator('.admin-tabs .admin-tab').nth(3).click();
    const published = page.locator('table.admin-table tbody tr').filter({ hasText: forumTopicTitle }).last();
    await expect(published).toBeVisible();
    const lock = published.locator('form[action$="/lock"]');
    const lockResponsePromise = page.waitForResponse((response) =>
      response.url().includes('/admin/forum/topics/') && response.url().endsWith('/lock') && response.request().method() === 'POST'
    );
    await lock.locator('button[type="submit"]').click();
    expect((await lockResponsePromise).status()).toBeLessThan(400);
    await page.waitForLoadState('domcontentloaded');

    await page.goto('/forum/general', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.forum-topic-row').filter({ hasText: forumTopicTitle }).locator('.forum-lock')).toBeVisible();

    await page.goto('/admin/forum', { waitUntil: 'domcontentloaded' });
    const locked = page.locator('table.admin-table tbody tr').filter({ hasText: forumTopicTitle }).last();
    const unlock = locked.locator('form[action$="/unlock"]');
    const unlockResponsePromise = page.waitForResponse((response) =>
      response.url().includes('/admin/forum/topics/') && response.url().endsWith('/unlock') && response.request().method() === 'POST'
    );
    await unlock.locator('button[type="submit"]').click();
    expect((await unlockResponsePromise).status()).toBeLessThan(400);
    await page.waitForLoadState('domcontentloaded');

    await page.goto('/forum/general', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.forum-topic-row').filter({ hasText: forumTopicTitle }).locator('.forum-lock')).toHaveCount(0);
  }
});
