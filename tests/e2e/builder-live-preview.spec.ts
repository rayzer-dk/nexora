import { expect, test, type Page } from '@playwright/test';

test.describe.configure({ retries: 0, mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

test('an edit reaches the preview page without saving; the storefront keeps the published page', async ({ page, browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating builder check runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  await page.goto('/admin/appearance/builder/home', { waitUntil: 'domcontentloaded' });
  const blocks = page.locator('[data-builder-list] [data-block-id]');
  await expect(blocks.first()).toBeVisible();
  const frame = page.frameLocator('[data-real-preview] iframe');
  await expect(frame.locator('body')).toBeVisible();

  const heading = `Живий перегляд ${Date.now()}`;
  await page.locator('[data-add-component]').selectOption('hero');
  await page.locator('[data-add-block]').click();
  await page.locator('[data-inspector] [data-field="props.title"]').first().fill(heading);
  await expect(page.locator('[data-builder-save-status]')).toHaveText(/\d{1,2}:\d{2}/, { timeout: 15_000 });
  await expect(frame.getByText(heading)).toBeVisible({ timeout: 15_000 });

  const shopper = await browser.newContext();
  const storefront = await shopper.newPage();
  await storefront.goto('/', { waitUntil: 'domcontentloaded' });
  await expect(storefront.locator('body')).not.toContainText(heading);
  await shopper.close();

  // The outline switch is remembered by the browser.
  await page.locator('[data-preview-mode="schema"]').click();
  await expect(page.locator('[data-preview-canvas]')).toBeVisible();
  await expect(page.locator('[data-real-preview]')).toBeHidden();
  // Back to the opened layout (autosaved) before reloading, so the draft stays as it was.
  const undo = page.locator('[data-builder-undo]');
  const saved = page.waitForResponse((response) => response.url().endsWith('/draft.json') && response.ok(), { timeout: 15_000 });
  while (await undo.isEnabled()) await undo.click();
  await expect(blocks.filter({ hasText: heading })).toHaveCount(0);
  await saved;
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-preview-mode="schema"]')).toHaveClass(/is-active/);
  await expect(page.locator('[data-real-preview]')).toBeHidden();
  await page.locator('[data-preview-mode="page"]').click();
  await expect(page.locator('[data-real-preview]')).toBeVisible();
});
