import { expect, test, type Page } from '@playwright/test';

test.describe.configure({ retries: 0, mode: 'serial' });

async function signIn(page: Page, password: string): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(password);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

test('the user menu leads to documents, the update page and every bundled document renders', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Shared admin session runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await signIn(page, process.env.E2E_ADMIN_PASSWORD!);

  await page.locator('[data-user-menu] summary').click();
  const menu = page.locator('.admin-user-menu__panel');
  await expect(menu).toBeVisible();
  for (const href of ['/admin/account', '/admin/account/security', '/admin/help', '/admin/system/update', '/admin/system/stability', '/admin/system/extensions']) {
    await expect(menu.locator(`a[href="${href}"]`)).toHaveCount(1);
  }
  await page.keyboard.press('Escape');
  await expect(menu).toBeHidden();

  await page.goto('/admin/help', { waitUntil: 'domcontentloaded' });
  const links = await page.locator('.admin-document-list a[href^="/admin/help/"]').evaluateAll((nodes) => nodes.map((node) => (node as HTMLAnchorElement).getAttribute('href')!));
  expect(links.length).toBeGreaterThanOrEqual(12);
  for (const href of links) {
    const response = await page.goto(href, { waitUntil: 'domcontentloaded' });
    expect(response?.status(), href).toBe(200);
    await expect(page.locator('.admin-doc'), href).not.toBeEmpty();
    await expect(page.locator('.admin-doc script'), href).toHaveCount(0);
  }
  await page.goto('/admin/help/update-and-restore', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.admin-doc')).toContainText('upgrade.php');
  expect((await page.goto('/admin/help/not-a-document', { waitUntil: 'domcontentloaded' }))?.status()).toBe(404);

  await page.goto('/admin/system/update', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.admin-stats')).toContainText(/\d+\.\d+\.\d+/);
  await expect(page.locator('.admin-status.is-success')).toBeVisible();
  await expect(page.locator('.admin-steps li')).toHaveCount(4);
});

test('an administrator changes the display name and the password, then restores both', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Shared admin session runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  const original = process.env.E2E_ADMIN_PASSWORD!;
  const changed = 'Nexora-E2E-2026-Changed!';
  let current = original;
  await signIn(page, current);

  await page.goto('/admin/account', { waitUntil: 'domcontentloaded' });
  const nameInput = page.locator('form[action$="/admin/account/profile"] input[name="display_name"]');
  const oldName = await nameInput.inputValue();
  await nameInput.fill('E2E Renamed');
  await Promise.all([page.waitForURL(/\/admin\/account$/), page.locator('form[action$="/admin/account/profile"] button[type="submit"]').click()]);
  await expect(page.locator('.admin-user__name')).toHaveText('E2E Renamed');

  const changePassword = async (from: string, to: string): Promise<void> => {
    await page.goto('/admin/account', { waitUntil: 'domcontentloaded' });
    const form = page.locator('form[action$="/admin/account/password"]');
    await form.locator('input[name="current_password"]').fill(from);
    await form.locator('input[name="new_password"]').fill(to);
    await form.locator('input[name="new_password_confirm"]').fill(to);
    await Promise.all([page.waitForURL(/\/admin\/login/), form.locator('button[type="submit"]').click()]);
  };

  try {
    // a wrong current password changes nothing
    await page.goto('/admin/account', { waitUntil: 'domcontentloaded' });
    const form = page.locator('form[action$="/admin/account/password"]');
    await form.locator('input[name="current_password"]').fill('definitely-wrong-password');
    await form.locator('input[name="new_password"]').fill(changed);
    await form.locator('input[name="new_password_confirm"]').fill(changed);
    await Promise.all([page.waitForURL(/\/admin\/account$/), form.locator('button[type="submit"]').click()]);
    await expect(page.locator('.admin-notice.is-error').first()).toBeAttached();

    await changePassword(original, changed);
    current = changed;
    await signIn(page, changed);
  } finally {
    if (current === changed) {
      await changePassword(changed, original);
      await signIn(page, original);
    }
    await page.goto('/admin/account', { waitUntil: 'domcontentloaded' });
    await page.locator('form[action$="/admin/account/profile"] input[name="display_name"]').fill(oldName);
    await Promise.all([page.waitForURL(/\/admin\/account$/), page.locator('form[action$="/admin/account/profile"] button[type="submit"]').click()]);
  }
  await expect(page.locator('.admin-user__name')).toHaveText(oldName);
});
