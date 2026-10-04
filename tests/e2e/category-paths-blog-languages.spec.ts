import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

// Release 3.45.0: main category of a product, optional category path in product addresses (the flat address stays
// canonical), blog subcategories, and a demo catalogue that is complete in Ukrainian, English and Russian.

test.describe.configure({ mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

async function setCategoryPath(page: Page, enabled: boolean): Promise<void> {
  await page.goto('/admin/catalog/categories', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const box = page.locator('.admin-category-url input[name="category_path"]');
  await box.setChecked(enabled);
  await Promise.all([page.waitForURL(/\/admin\/catalog\/categories/), page.locator('.admin-category-url button[type="submit"]').click()]);
  await expect(page.locator('.admin-category-url input[name="category_path"]')).toBeChecked({ checked: enabled });
}

test('the category path in product addresses is optional, the flat address stays canonical', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating setting runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await loginAdmin(page);

  // Off by default: a path with categories is not a page.
  const off = await page.request.get('/electronics/phones-tablets/smartphones/smartphones-apple/iphone-5s', { maxRedirects: 0 });
  expect(off.status()).toBe(404);

  await setCategoryPath(page, true);
  try {
    await page.goto('/smartphones-apple', { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    const link = page.locator('.catalog-card a[href$="/iphone-5s"]').first();
    await expect(link).toHaveAttribute('href', '/electronics/phones-tablets/smartphones/smartphones-apple/iphone-5s');

    const nested = await page.goto('/electronics/phones-tablets/smartphones/smartphones-apple/iphone-5s', { waitUntil: 'domcontentloaded' });
    expect(nested?.status()).toBe(200);
    await expect(page.locator('h1').first()).toContainText('iPhone 5s');
    expect(await page.locator('link[rel="canonical"]').getAttribute('href')).toMatch(/^https?:\/\/[^/]+\/iphone-5s$/);

    // A parent path works too, a chain that does not lead to the product does not.
    expect((await page.request.get('/electronics/phones-tablets/smartphones/iphone-5s', { maxRedirects: 0 })).status()).toBe(200);
    expect((await page.request.get('/electronics/smartphones-apple/iphone-5s', { maxRedirects: 0 })).status()).toBe(404);
    expect((await page.request.get('/phones-tablets/smartphones/smartphones-apple/iphone-5s', { maxRedirects: 0 })).status()).toBe(404);
  } finally {
    await setCategoryPath(page, false);
  }
  expect((await page.request.get('/iphone-5s', { maxRedirects: 0 })).status()).toBe(200);
  expect((await page.request.get('/electronics/phones-tablets/smartphones/smartphones-apple/iphone-5s', { maxRedirects: 0 })).status()).toBe(404);
});

test('the main category of a product is chosen explicitly and shown only with two or more categories', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating product form runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  test.setTimeout(90000);
  await loginAdmin(page);
  await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
  await page.locator('tr').filter({ hasNotText: /Picked|E2E|Warm/i }).locator('a[href*="/admin/catalog/products/"][href$="/edit"]').first().click();
  await page.waitForURL(/\/edit/);
  const editUrl = page.url();

  const boxes = page.locator('input[name="category_ids[]"]');
  const original = await boxes.evaluateAll((nodes) => nodes.filter((node) => (node as HTMLInputElement).checked).map((node) => (node as HTMLInputElement).value));
  const primarySelect = page.locator('[data-primary-category-select]');
  const wrapper = page.locator('[data-primary-category]');
  const save = async (): Promise<void> => {
    await Promise.all([
      page.waitForResponse((r) => r.request().method() === 'POST' && /\/edit$/.test(new URL(r.url()).pathname)),
      page.locator('[data-primary-submit]').first().click(),
    ]);
    await page.waitForLoadState('domcontentloaded');
  };
  const setCategories = async (values: string[]): Promise<void> => {
    await page.locator('[data-multiselect] summary').first().click();
    for (const value of await boxes.evaluateAll((nodes) => nodes.map((node) => (node as HTMLInputElement).value))) {
      await page.locator(`input[name="category_ids[]"][value="${value}"]`).setChecked(values.includes(value), { force: true });
    }
    await page.locator('[data-multiselect] summary').first().click(); // close the list: it would cover the save button
  };

  try {
    const all = await boxes.evaluateAll((nodes) => nodes.map((node) => (node as HTMLInputElement).value));
    expect(all.length).toBeGreaterThanOrEqual(3);
    const [first, second] = all.filter((value) => !original.includes(value)).slice(0, 2).concat(original).slice(0, 2);

    await setCategories([first]);
    await expect(wrapper).toBeHidden();
    await setCategories([first, second]);
    await expect(wrapper).toBeVisible();
    await expect(primarySelect.locator('option:not([disabled])')).toHaveCount(2);
    await primarySelect.selectOption(second);
    await save();

    await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
    await expect(wrapper).toBeVisible();
    await expect(primarySelect).toHaveValue(second);
  } finally {
    await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
    await setCategories(original);
    if (original.length > 1) await page.locator('[data-primary-category-select]').selectOption(original[0]);
    await save();
  }
});

test('blog categories have subcategories: parent lists the whole branch and the chips show the level below', async ({ page }) => {
  await page.goto('/blog/category/demo-guides', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  const parentCount = await page.locator('.blog-card, article.blog-card, [data-blog-card]').count();
  const sub = page.locator('.blog-chips--sub a');
  expect(await sub.count()).toBeGreaterThanOrEqual(2);

  await sub.first().click();
  await page.waitForLoadState('domcontentloaded');
  await expectNoServerError(page);
  await expect(page.locator('.blog-chips--sub a[aria-current="page"]')).toHaveCount(1);
  await expect(page.locator('.blog-chips:not(.blog-chips--sub) a[aria-current="true"]')).toHaveCount(1);
  const childCount = await page.locator('.blog-card, article.blog-card, [data-blog-card]').count();
  expect(childCount).toBeGreaterThan(0);
  expect(parentCount).toBeGreaterThanOrEqual(childCount);
});

test('the blog category admin edits the parent and shows the tree', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Admin form runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
  await loginAdmin(page);
  await page.goto('/admin/content/blog/categories', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  await expect(page.locator('select[name="parent_id"]')).toBeVisible();
  expect(await page.locator('.admin-tree-cell[style*="--depth: 1"]').count()).toBeGreaterThanOrEqual(2);
});

test('the demo catalogue is complete in Ukrainian, English and Russian', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Language contract runs once.');
  const titles = async (lang: string): Promise<string[]> => {
    await page.goto(`/catalog?lang=${lang}`, { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    return page.locator('[data-product-card] h2 a').evaluateAll((nodes) => nodes.map((node) => (node.textContent || '').trim()));
  };
  const cyrillic = /[Ѐ-ӿ]/;
  const en = await titles('en-US');
  const ru = await titles('ru-RU');
  const uk = await titles('uk-UA');
  expect(en.length).toBeGreaterThan(5);
  expect(en.filter((title) => cyrillic.test(title))).toEqual([]);
  expect(ru.filter((title) => cyrillic.test(title)).length).toBeGreaterThan(0);
  expect(uk.filter((title) => cyrillic.test(title)).length).toBeGreaterThan(0);

  // The category tree is translated as well.
  await page.goto('/catalog?lang=en-US', { waitUntil: 'domcontentloaded' });
  const chips = await page.locator('.category-chips a').evaluateAll((nodes) => nodes.map((node) => (node.textContent || '').trim()));
  expect(chips.length).toBeGreaterThan(3);
  expect(chips.filter((chip) => cyrillic.test(chip))).toEqual([]);
});
