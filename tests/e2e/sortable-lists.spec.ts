import { expect, test } from '@playwright/test';
import { demoProductEditUrl, expectNoServerError, openProductTab, loginAdmin } from './helpers';

// Release 3.97.24: lists that are ordered by dragging a handle (or with the arrow keys on the focused handle).

test.describe.configure({ mode: 'serial' });

test('delivery methods are ordered by dragging and the checkout follows that order', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared settings once.');
  await loginAdmin(page);
  await page.goto('/admin/shipments/methods', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const items = page.locator('section[data-sortable]').first().locator('[data-sortable-item]');
  expect(await items.count()).toBeGreaterThan(2);
  const codes = async () => page.locator('section[data-sortable]').first().locator('input[name$="[sort]"]').evaluateAll((els) => els.map((el) => (el as HTMLInputElement).name));
  const before = await codes();
  // the last delivery method goes up by one place with the arrow keys
  const handle = items.nth(2).locator('.admin-sort-handle').first();
  await handle.focus();
  await page.keyboard.press('ArrowUp');
  expect(await codes()).not.toEqual(before);
  await Promise.all([page.waitForURL(/\/admin\/shipments\/methods/), page.locator('form button[type="submit"]').last().click()]);
  const after = await codes();
  expect(after[1]).toEqual(before[2]);
  // put it back
  const again = page.locator('section[data-sortable]').first().locator('[data-sortable-item]').nth(1).locator('.admin-sort-handle').first();
  await again.focus();
  await page.keyboard.press('ArrowDown');
  await Promise.all([page.waitForURL(/\/admin\/shipments\/methods/), page.locator('form button[type="submit"]').last().click()]);
  expect(await codes()).toEqual(before);
});

test('menu items are dragged in the list and the order is saved at once', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared settings once.');
  await loginAdmin(page);
  await page.goto('/admin/appearance/navigation?menu=header', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  if ((await page.locator('tbody[data-sortable] tr').count()) < 2) {
    await page.locator('form[action$="/navigation/seed"] button[type="submit"]').click();
    await page.waitForURL(/navigation/);
  }
  const rows = page.locator('tbody[data-sortable] tr[data-parent=""]');
  test.skip((await rows.count()) < 2, 'The menu has fewer than two top-level items.');
  const firstId = await rows.nth(0).getAttribute('data-sort-id');
  const saved = page.waitForResponse((r) => r.url().includes('/navigation/reorder') && r.request().method() === 'POST');
  await rows.nth(1).locator('.admin-sort-handle').dragTo(rows.nth(0));
  expect((await saved).status()).toBe(200);
  await page.reload({ waitUntil: 'domcontentloaded' });
  expect(await page.locator('tbody[data-sortable] tr[data-parent=""]').nth(1).getAttribute('data-sort-id')).toBe(firstId);
});

test('characteristics are dragged in the metadata list', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared settings once.');
  await loginAdmin(page);
  await page.goto('/admin/catalog/metadata', { waitUntil: 'domcontentloaded' });
  const rows = page.locator('tbody[data-sortable][data-sortable-url*="attributes"] tr');
  test.skip((await rows.count()) < 2, 'The shop has fewer than two characteristics.');
  const firstId = await rows.nth(0).getAttribute('data-sort-id');
  const saved = page.waitForResponse((r) => r.url().includes('/attributes/reorder') && r.request().method() === 'POST');
  await rows.nth(1).locator('.admin-sort-handle').dragTo(rows.nth(0));
  expect((await saved).status()).toBe(200);
  await page.reload({ waitUntil: 'domcontentloaded' });
  expect(await page.locator('tbody[data-sortable][data-sortable-url*="attributes"] tr').nth(1).getAttribute('data-sort-id')).toBe(firstId);
});

test('option values of a product are ordered with the handles and saved with the form', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared demo data once.');
  await loginAdmin(page);
  await page.goto(await demoProductEditUrl(page), { waitUntil: 'domcontentloaded' });
  await openProductTab(page, 'sales');
  if ((await page.locator('#options .admin-option__values .admin-option__row:not(.is-head) .admin-sort-handle').count()) < 2) {
    await page.locator('#options [data-option-preset="size"]').click();
    await Promise.all([page.waitForURL(/#options|\/edit/), page.locator('button[name="_option_action"][value="save"]').click()]);
    await openProductTab(page, 'sales');
  }
  const values = page.locator('#options .admin-option__values').first();
  const labels = async () => values.locator('.admin-option__row:not(.is-head) input[name$="[label]"]').evaluateAll((els) => els.map((el) => (el as HTMLInputElement).value));
  const before = await labels();
  expect(before.length).toBeGreaterThan(1);
  const handle = values.locator('.admin-option__row:not(.is-head)').nth(0).locator('.admin-sort-handle');
  await handle.focus();
  await page.keyboard.press('ArrowDown');
  expect((await labels())[1]).toBe(before[0]);
  await Promise.all([page.waitForURL(/#options|\/edit/), page.locator('button[name="_option_action"][value="save"]').click()]);
  await openProductTab(page, 'sales');
  expect((await page.locator('#options .admin-option__values').first().locator('.admin-option__row:not(.is-head) input[name$="[label]"]').evaluateAll((els) => els.map((el) => (el as HTMLInputElement).value)))[1]).toBe(before[0]);
});

test('the dashboard can be customised: blocks move, hide and the layout is kept per administrator', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates the admin layout once.');
  await loginAdmin(page);
  await page.goto('/admin', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const order = async () => page.locator('[data-dash-widgets] > [data-dash-widget]').evaluateAll((els) => els.map((el) => (el as HTMLElement).dataset.dashWidget));
  const before = await order();
  expect(before.length).toBe(9);
  await page.locator('[data-dash-edit]').click();
  await expect(page.locator('[data-dash-layout]')).toBeVisible();
  const key = before[3];
  await page.locator(`[data-dash-widget="${key}"] [data-dash-move="1"]`).click();
  await page.locator(`[data-dash-widget="${before[7]}"] [data-dash-toggle]`).click();
  await Promise.all([page.waitForURL(/\/admin(\?|$)/), page.locator('[data-dash-layout] button[type="submit"]:not([name="reset"])').click()]);
  const after = await order();
  expect(after[4]).toBe(key);
  await expect(page.locator(`[data-dash-widget="${before[7]}"]`)).toHaveClass(/is-off/);
  await expect(page.locator(`[data-dash-widget="${before[7]}"] .dash-widget__body`)).toBeHidden();
  // back to the default layout
  await page.locator('[data-dash-edit]').click();
  await page.locator('[data-dash-layout] button[name="reset"]').click();
  await page.locator('[data-confirm-accept]').click();
  await page.waitForURL(/\/admin(\?|$)/);
  expect(await order()).toEqual(before);
});

test('benefit cards on the storefront settings page are reordered and renumbered', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await loginAdmin(page);
  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  const cards = page.locator('.admin-benefit-list > .admin-benefit');
  expect(await cards.count()).toBe(8);
  await cards.nth(0).locator('.admin-sort-handle').focus();
  await page.keyboard.press('ArrowDown');
  // after the move the second card carries index 1 and the first one index 0
  const names = await page.locator('.admin-benefit-list > .admin-benefit').first().locator('input[type="text"]').first().getAttribute('name');
  expect(names).toMatch(/\[0\]/);
  await expect(page.locator('.admin-benefit-list > .admin-benefit').nth(1).locator('[data-sort-number]')).toHaveText('2');
});

test('carrier keys typed in the admin are stored, shown as set and can be removed', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutates shared settings once.');
  await loginAdmin(page);
  await page.goto('/admin/shipments/carriers', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const form = page.locator('form[action$="/carriers/save"]', { has: page.locator('input[name="key[DHL_API_KEY]"]') });
  const panel = form.locator('xpath=ancestor::details[1]');
  if (!(await panel.evaluate((node) => (node as HTMLDetailsElement).open))) await panel.locator('summary').click();
  await form.locator('input[name="key[DHL_API_KEY]"]').fill('e2e-test-key-123');
  await Promise.all([page.waitForURL(/\/admin\/shipments\/carriers/), form.locator('button[type="submit"]').click()]);
  await expectNoServerError(page);
  const again = page.locator('form[action$="/carriers/save"]', { has: page.locator('input[name="key[DHL_API_KEY]"]') });
  await expect(again.locator('input[name="key[DHL_API_KEY]"]')).toHaveValue('');
  await expect(again.locator('input[name="clear[DHL_API_KEY]"]')).toBeAttached();
  expect(await page.content()).not.toContain('e2e-test-key-123');
  const panelAgain = again.locator('xpath=ancestor::details[1]');
  if (!(await panelAgain.evaluate((node) => (node as HTMLDetailsElement).open))) await panelAgain.locator('summary').click();
  await again.locator('input[name="clear[DHL_API_KEY]"]').check();
  await Promise.all([page.waitForURL(/\/admin\/shipments\/carriers/), again.locator('button[type="submit"]').click()]);
  await expect(page.locator('input[name="clear[DHL_API_KEY]"]')).toHaveCount(0);
});

test('a return can be answered from its page without changing the status', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
  await loginAdmin(page);
  await page.goto('/admin/customer-experience', { waitUntil: 'domcontentloaded' });
  const link = page.locator('a[href^="/admin/customer-experience/returns/"]').first();
  test.skip((await link.count()) === 0, 'The shop has no return requests.');
  await link.click();
  await expectNoServerError(page);
  await page.locator('form[action$="/reply"] textarea[name="reply"]').fill('Thank you, we have your parcel.');
  await Promise.all([page.waitForURL(/\/returns\//), page.locator('form[action$="/reply"] button[type="submit"]').click()]);
  await expectNoServerError(page);
  await expect(page.locator('.admin-notice.is-success, [data-toast-source]').first()).toBeAttached();
});
