import { expect, test, type Page } from '@playwright/test';

test.describe.configure({ retries: 0, mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

test('undo and redo: buttons and keys revert adding, typing, switching off and deleting', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating builder check runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  await page.goto('/admin/appearance/builder/home', { waitUntil: 'domcontentloaded' });
  const blocks = page.locator('[data-builder-list] [data-block-id]');
  const undo = page.locator('[data-builder-undo]');
  const redo = page.locator('[data-builder-redo]');
  await expect(blocks.first()).toBeVisible();
  await expect(undo).toBeDisabled();
  await expect(redo).toBeDisabled();
  const start = await blocks.count();

  await page.locator('[data-add-component]').selectOption('hero');
  await page.locator('[data-add-block]').click();
  await expect(blocks).toHaveCount(start + 1);
  await undo.click();
  await expect(blocks).toHaveCount(start);
  await expect(undo).toBeDisabled();
  await redo.click();
  await expect(blocks).toHaveCount(start + 1);
  await expect(redo).toBeDisabled();

  await page.locator('h1').first().click();
  await page.keyboard.press('Control+z');
  await expect(blocks).toHaveCount(start);
  await page.keyboard.press('Control+Shift+z');
  await expect(blocks).toHaveCount(start + 1);
  await page.keyboard.press('Control+z');
  await page.keyboard.press('Control+y');
  await expect(blocks).toHaveCount(start + 1);

  await blocks.last().locator('[data-select]').click();
  const title = page.locator('[data-inspector] [data-field="props.title"]').first();
  await title.click();
  await title.pressSequentially('Весняний розпродаж', { delay: 30 });
  await expect(blocks.last()).toContainText('Весняний розпродаж');
  await undo.click();
  await expect(blocks).toHaveCount(start + 1);
  await expect(blocks.last()).not.toContainText('Весняний');
  await expect(page.locator('[data-inspector] [data-field="props.title"]').first()).toHaveValue('');

  await blocks.last().locator('[data-enabled]').uncheck();
  await expect(blocks.last().locator('[data-enabled]')).not.toBeChecked();
  await undo.click();
  await expect(blocks.last().locator('[data-enabled]')).toBeChecked();

  await blocks.last().locator('[data-delete]').click();
  await expect(blocks).toHaveCount(start);
  await undo.click();
  await expect(blocks).toHaveCount(start + 1);

  // Leave the stored draft as it was opened: undo everything; a draft saved meanwhile is overwritten after the 500 ms pause.
  while (await undo.isEnabled()) await undo.click();
  await expect(blocks).toHaveCount(start);
  await page.waitForTimeout(700);
  await expect(page.locator('[data-builder-save-status]')).not.toHaveText(/…$/, { timeout: 15_000 });
});
