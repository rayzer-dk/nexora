import { expect, test } from '@playwright/test';

test('a product H1 set in the editor replaces the name in the page heading only', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating data runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);

  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  const editHref = await page.locator('table.admin-table tbody tr').filter({ has: page.locator('[data-quick-status] input:checked') }).first().locator('a[href$="/edit"]').first().getAttribute('href');
  expect(editHref).toBeTruthy();

  const save = async (h1: string): Promise<string> => {
    await page.goto(editHref!, { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="h1"]').evaluate((el: HTMLInputElement, v: string) => { el.value = v; }, h1);
    await Promise.all([
      page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/edit')),
      page.locator('#product-form').evaluate((form: HTMLFormElement) => form.requestSubmit()),
    ]);
    await page.waitForLoadState('load');
    await page.goto(editHref!, { waitUntil: 'domcontentloaded' });
    return page.locator('input[name="slug"]').inputValue();
  };

  const marker = `H1 override ${Date.now()}`;
  const slug = await save(marker);
  try {
    await page.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('h1').first()).toHaveText(marker);
  } finally {
    await save('');
  }
  await page.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('h1').first()).not.toHaveText(marker);
});
