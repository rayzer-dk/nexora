import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')), page.locator('button[type="submit"]').click()]);
}

test('a brand created and renamed in admin is offered in the product form', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating catalog CRUD runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  const suffix = Date.now();
  const name = `E2E Brand ${suffix}`;
  const renamed = `E2E Brand Renamed ${suffix}`;

  await loginAdmin(page);
  await page.goto('/admin/catalog/metadata', { waitUntil: 'domcontentloaded' });
  const create = page.locator('form[action$="/metadata/brands/create"]');
  // The add form sits in a collapsed block: open it like a person would.
  await create.evaluate((form) => { const d = form.closest('details'); if (d) d.open = true; });
  await create.locator('input[name="name"]').fill(name);
  await create.locator('input[name="slug"]').fill(`e2e-brand-${suffix}`);
  await Promise.all([page.waitForLoadState('domcontentloaded'), create.locator('button[type="submit"]').click()]);
  await expectNoServerError(page);

  const row = page.locator('tr', { has: page.locator(`input[name="name"][value="${name}"]`) });
  await expect(row).toHaveCount(1);
  await row.locator('input[name="name"]').fill(renamed);
  await Promise.all([page.waitForLoadState('domcontentloaded'), row.locator('button[type="submit"]').click()]);
  await expectNoServerError(page);
  await expect(page.locator(`input[name="name"][value="${renamed}"]`)).toHaveCount(1);

  await page.goto('/admin/catalog/products/new', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('select[name="brand_id"] option', { hasText: renamed })).toHaveCount(1);
});
