import { expect, test } from '@playwright/test';
import { loginAdmin } from './helpers';

test('category H1, title and description set in the editor reach the storefront page', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating data runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  await page.goto('/admin/catalog/categories', { waitUntil: 'domcontentloaded' });
  const editHref = await page.locator('table.admin-table tbody tr a[href$="/edit"]').first().getAttribute('href');
  expect(editHref).toBeTruthy();

  await page.goto(editHref!, { waitUntil: 'domcontentloaded' });
  const original = {
    h1: await page.locator('input[name="h1"]').inputValue(),
    title: await page.locator('input[name="meta_title"]').inputValue(),
    description: await page.locator('textarea[name="meta_description"]').inputValue(),
  };

  const save = async (h1: string, title: string, description: string): Promise<string> => {
    await page.goto(editHref!, { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="h1"]').fill(h1);
    await page.locator('input[name="meta_title"]').fill(title);
    await page.locator('textarea[name="meta_description"]').fill(description);

    // The old /\/admin\/catalog\/categories/ URL wait also matched the edit page.
    // It sometimes completed *before* the POST; the next goto aborted the save.
    const editPath = new URL(editHref!, page.url()).pathname;
    const posted = page.waitForResponse((response) =>
      response.request().method() === 'POST' && new URL(response.url()).pathname === editPath
    );
    await page.locator('form.admin-form button[type="submit"]').first().click();
    const response = await posted;
    expect(response.status(), 'the category edit POST must succeed').toBeLessThan(400);
    await page.waitForURL((url) => url.pathname === '/admin/catalog/categories');

    await page.goto(editHref!, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('input[name="h1"]')).toHaveValue(h1);
    await expect(page.locator('input[name="meta_title"]')).toHaveValue(title);
    await expect(page.locator('textarea[name="meta_description"]')).toHaveValue(description);
    return page.locator('input[name="slug"]').inputValue();
  };

  const stamp = Date.now();
  try {
    const slug = await save(`Cat H1 ${stamp}`, `Cat title ${stamp}`, `Cat description ${stamp}`);
    await page.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('h1').first()).toHaveText(`Cat H1 ${stamp}`);
    await expect(page).toHaveTitle(new RegExp(`Cat title ${stamp}`));
    await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', new RegExp(`Cat description ${stamp}`));
  } finally {
    // Preserve the real state (including any original SEO overrides), not assumed empty fields.
    await save(original.h1, original.title, original.description);
  }
});
