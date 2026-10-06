import { expect, test } from '@playwright/test';

test('category H1, title and description set in the editor reach the storefront page', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating data runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);

  await page.goto('/admin/catalog/categories', { waitUntil: 'domcontentloaded' });
  const editHref = await page.locator('table.admin-table tbody tr a[href$="/edit"]').first().getAttribute('href');
  expect(editHref).toBeTruthy();

  const save = async (h1: string, title: string, description: string): Promise<string> => {
    await page.goto(editHref!, { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="h1"]').evaluate((el: HTMLInputElement, v: string) => { el.value = v; }, h1);
    await page.locator('input[name="meta_title"]').evaluate((el: HTMLInputElement, v: string) => { el.value = v; }, title);
    await page.locator('textarea[name="meta_description"]').evaluate((el: HTMLTextAreaElement, v: string) => { el.value = v; }, description);
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form.admin-form').first().evaluate((f: HTMLFormElement) => f.requestSubmit())]);
    await page.goto(editHref!, { waitUntil: 'domcontentloaded' });
    return page.locator('input[name="slug"]').inputValue();
  };

  const stamp = Date.now();
  const slug = await save(`Cat H1 ${stamp}`, `Cat title ${stamp}`, `Cat description ${stamp}`);
  try {
    await page.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('h1').first()).toHaveText(`Cat H1 ${stamp}`);
    await expect(page).toHaveTitle(new RegExp(`Cat title ${stamp}`));
    await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', new RegExp(`Cat description ${stamp}`));
  } finally {
    await save('', '', '');
  }
});
