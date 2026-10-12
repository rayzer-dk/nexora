import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

// Release 3.97.24: the order board and the waybill stickers.

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

test('an order moves across the board by select and by drag, and stickers print for selected orders', async ({ page, context }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates an order once.');
  test.setTimeout(300_000);
  page.setDefaultNavigationTimeout(120_000);
  // other specs use up the stock of the demo product: put some back
  execFileSync('php', ['bin/console', 'dbal:run-sql', "UPDATE mc_stock_level sl JOIN mc_variant_inventory_item vii ON vii.inventory_item_id=sl.inventory_item_id JOIN mc_product_variant v ON v.id=vii.variant_id SET sl.stocked_quantity=sl.reserved_quantity+50 WHERE v.sku='DEMO-078'"], { env: process.env, stdio: 'ignore' });
  await loginAdmin(page);

  // a fresh order lands in "New"
  await page.goto('/admin/orders/manual', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="name"]').fill('Дошка Клієнт');
  await page.locator('input[name="phone"]').fill('+380501112288');
  await page.locator('input[name="sku[]"]').first().fill('DEMO-078');
  await page.locator('input[name="qty[]"]').first().fill('1');
  await page.locator('input[name="delivery"]').fill('Одеса, відділення 12');
  await Promise.all([page.waitForURL(/\/admin\/orders\/[0-9a-f-]{36}/), page.locator('main form button[type="submit"]').last().click()]);
  const orderId = page.url().match(/orders\/([0-9a-f-]{36})/)![1];

  await page.goto('/admin/orders/board', { waitUntil: 'domcontentloaded' });
  const card = page.locator(`[data-board-card][data-order="${orderId}"]`);
  await expect(card).toBeVisible();
  await expect(page.locator('[data-board-column][data-stage="new"]').locator(card)).toHaveCount(1);

  // by the select
  await card.locator('[data-board-move]').selectOption('preparing');
  await expect(page.locator('[data-board-column][data-stage="preparing"]').locator(card)).toHaveCount(1);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-board-column][data-stage="preparing"] ' + `[data-order="${orderId}"]`)).toHaveCount(1);

  // by drag and drop
  await page.locator(`[data-board-card][data-order="${orderId}"]`).dragTo(page.locator('[data-board-column][data-stage="ready_for_pickup"] [data-board-cards]'));
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-board-column][data-stage="ready_for_pickup"] ' + `[data-order="${orderId}"]`)).toHaveCount(1);

  // a move back to "New" is refused by the server and the card stays where it was
  const bad = await page.request.post('/admin/orders/board/move', { data: { order: orderId, stage: 'new' }, headers: { 'X-CSRF-Token': 'wrong' } });
  expect(bad.status()).toBe(403);

  // stickers for the selected orders open in a new tab
  await page.goto('/admin/orders', { waitUntil: 'domcontentloaded' });
  await page.locator('tbody input[type="checkbox"]').first().check();
  const [popup] = await Promise.all([context.waitForEvent('page'), page.locator('[data-print-stickers]').click()]);
  await popup.waitForLoadState('domcontentloaded');
  await expect(popup.locator('[data-sticker]')).toHaveCount(1);
  expect(await popup.locator('[data-sticker] svg rect').count()).toBeGreaterThan(20);
  await expect(popup.locator('[data-print]')).toBeVisible();
  await popup.close();
});
