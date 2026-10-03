import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

async function login(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(email);
  await page.locator('input[name="_password"]').fill(password);
  await Promise.all([
    page.waitForURL(/\/admin(?:\/(?!login)|$)/),
    page.locator('button[type="submit"]').click(),
  ]);
}

test('ROLE_VIEWER is genuinely read-only and store scoped at the server', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating RBAC contract runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  const suffix = Date.now();
  const email = `e2e-viewer-${suffix}@example.test`;
  const password = 'Nexora-Viewer-2026!';
  await login(page, process.env.E2E_ADMIN_EMAIL!, process.env.E2E_ADMIN_PASSWORD!);

  await page.goto('/admin/system/access', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const createPanel = page.locator('details.admin-panel').filter({
    has: page.locator('form[action="/admin/system/access/user/save"] input[name="display_name"]:not([value])'),
  }).last();
  await expect(createPanel).toBeVisible();
  if (!(await createPanel.getAttribute('open'))) {
    await createPanel.locator('summary').click();
  }
  const create = createPanel.locator('form[action="/admin/system/access/user/save"]');
  await expect(create).toBeVisible();
  await create.locator('input[name="display_name"]').fill('E2E Read Only');
  await create.locator('input[name="email"]').fill(email);
  await create.locator('input[name="password"]').fill(password);
  const viewerRole = create.locator('input[name="roles[]"][value="ROLE_VIEWER"]');
  await viewerRole.check();
  const store = create.locator('input[name="stores[]"]').first();
  await expect(store).toBeVisible();
  await store.check();

  const createResponse = page.waitForResponse((response) =>
    response.url().endsWith('/admin/system/access/user/save') && response.request().method() === 'POST'
  );
  await create.locator('button[type="submit"]').click();
  expect((await createResponse).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('.admin-notice.is-error')).toHaveCount(0);
  await expect(page.locator('details.admin-panel > summary').filter({ hasText: email })).toBeVisible();

  const logout = page.locator('form[action="/admin/logout"]');
  if (await logout.count()) {
    await Promise.all([
      page.waitForURL((url) => !url.pathname.startsWith('/admin')), // logout returns to the storefront
      logout.evaluate((form: HTMLFormElement) => form.requestSubmit()),
    ]);
  } else {
    await page.context().clearCookies();
    await page.goto('/admin/login');
  }

  await login(page, email, password);
  await expectNoServerError(page);

  const catalog = await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  expect(catalog?.status()).toBe(200);
  await expectNoServerError(page);

  const orders = await page.goto('/admin/orders', { waitUntil: 'domcontentloaded' });
  expect(orders?.status()).toBe(200);
  await expectNoServerError(page);

  const createProduct = await page.goto('/admin/catalog/products/new', { waitUntil: 'domcontentloaded' });
  expect(createProduct?.status()).toBe(403);

  const systemSettings = await page.goto('/admin/system/store', { waitUntil: 'domcontentloaded' });
  expect(systemSettings?.status()).toBe(403);

  const accessMatrix = await page.goto('/admin/system/access', { waitUntil: 'domcontentloaded' });
  expect(accessMatrix?.status()).toBe(403);
});
