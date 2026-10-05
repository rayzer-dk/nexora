import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { expectNoServerError } from './helpers';

type Variant = { sku: string; price_minor: number; stock: number };
const variant = (sku = ''): Variant => JSON.parse(execFileSync('php', ['tests/e2e/read-variant.php', ...(sku ? [sku] : [])], { encoding: 'utf8', env: process.env }));

test('a supplier feed is previewed, applied to price and stock, and lists new offers', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating catalogue data runs once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  const before = variant();
  const newPriceMinor = before.price_minor + 1000;
  const newStock = before.stock + 3;
  const feed = (priceMinor: number, stock: number) =>
    `http://127.0.0.1:8099/supplier-feed.xml?sku=${encodeURIComponent(before.sku)}&price=${(priceMinor / 100).toFixed(2)}&stock=${stock}`;

  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);

  await page.goto('/admin/catalog/suppliers', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const form = page.locator('form[action$="/suppliers/save"]');
  await form.locator('input[name="name"]').fill(`E2E supplier ${Date.now()}`);
  await form.locator('input[name="feed_url"]').fill(feed(newPriceMinor, newStock));
  await Promise.all([page.waitForURL(/\/admin\/catalog\/suppliers\?id=\d+/), form.locator('button[type="submit"]').click()]);
  const supplierUrl = page.url();

  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form[action$="/run"] button[type="submit"]').click()]);
  await expectNoServerError(page);
  const row = page.locator('#supplier-changes tbody tr').filter({ hasText: before.sku });
  await expect(row).toBeVisible();
  await expect(page.getByText(`NEW-${before.sku}`)).toBeVisible();

  // Nothing changes until the owner applies it.
  expect(variant(before.sku).price_minor).toBe(before.price_minor);
  await row.locator('input[type="checkbox"]').check();
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('#supplier-changes button[name="what"][value="both"]').click()]);
  await expectNoServerError(page);
  const after = variant(before.sku);
  expect(after.price_minor).toBe(newPriceMinor);
  expect(after.stock).toBe(newStock);

  // Put the original price and stock back through a second feed, then remove the supplier.
  await page.goto(supplierUrl, { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="feed_url"]').fill(feed(before.price_minor, before.stock));
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form[action$="/suppliers/save"] button[type="submit"]').click()]);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form[action$="/run"] button[type="submit"]').click()]);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('#supplier-changes button[name="scope"][value="all"]').click().then(() => page.locator('[data-confirm-accept]').click())]);
  const restored = variant(before.sku);
  expect(restored.price_minor).toBe(before.price_minor);
  expect(restored.stock).toBe(before.stock);

  await page.goto(supplierUrl, { waitUntil: 'domcontentloaded' });
  await page.locator('form[action$="/delete"] button[type="submit"]').click();
  await page.locator('[data-confirm-accept]').click();
  await page.waitForLoadState('domcontentloaded');
});
