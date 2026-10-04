import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

// Release 3.39.0: one-click publish / draft in the product list and a per-page index / noindex switch in the product editor.

async function login(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL((url) => url.pathname.startsWith('/admin') && !url.pathname.startsWith('/admin/login')),
    page.locator('button[type="submit"]').click(),
  ]);
}

test('product list: the status switch publishes and hides a product without leaving the list', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating data runs once.');
  await login(page);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const url = await page.locator('[data-quick-status]:has(input:checked)').first().getAttribute('data-url');
  expect(url).toBeTruthy();
  const box = page.locator(`[data-quick-status][data-url="${url}"]`);
  const sw = box.locator('input');
  const badge = box.locator('xpath=ancestor::td').locator('.admin-badge').first();
  const off = page.waitForResponse((r) => r.url().includes('/quick-status') && r.request().method() === 'POST');
  await sw.evaluate((el: HTMLInputElement) => el.click());
  expect((await off).status()).toBe(200);
  await expect(sw).not.toBeChecked();
  await expect(badge).toHaveClass(/is-warning/);

  try {
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(sw).not.toBeChecked(); // stored
  } finally {
    const on = page.waitForResponse((r) => r.url().includes('/quick-status') && r.request().method() === 'POST');
    if (await sw.isChecked()) {
      await on.catch(() => undefined);
    } else {
      await sw.evaluate((el: HTMLInputElement) => el.click());
      expect((await on).status()).toBe(200);
    }
  }
  await expect(sw).toBeChecked();
});

test('product editor: noindex adds the robots meta and the sitemap drops the page', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating data runs once.');
  await login(page);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  const editHref = await page.locator('table.admin-table tbody tr').filter({ has: page.locator('[data-quick-status] input:checked') }).first().locator('a[href$="/edit"]').first().getAttribute('href');
  expect(editHref).toBeTruthy();

  const setIndexable = async (value: boolean): Promise<void> => {
    await page.goto(editHref!, { waitUntil: 'domcontentloaded' });
    const box = page.locator('input[name="indexable"]');
    if ((await box.isChecked()) !== value) await box.evaluate((el: HTMLInputElement) => el.click());
    await Promise.all([
      page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/edit')),
      page.locator('#product-form').evaluate((form: HTMLFormElement) => form.requestSubmit()),
    ]);
    await page.waitForLoadState('load');
  };

  await page.goto(editHref!, { waitUntil: 'domcontentloaded' });
  const slug = await page.locator('input[name="slug"]').inputValue();
  expect(slug).not.toBe('');
  try {
    await setIndexable(false);
    const html = await (await page.request.get(`/${slug}`)).text();
    expect(html).toContain('content="noindex,follow"');
    const sitemap = await (await page.request.get('/sitemaps/default/uk-UA/1.xml')).text();
    expect(sitemap).not.toContain(`/${slug}<`);
  } finally {
    await setIndexable(true);
  }
  const back = await (await page.request.get(`/${slug}`)).text();
  expect(back).toContain('index,follow,max-image-preview:large');
});
