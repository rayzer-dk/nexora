import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL(/\/admin(?:\/(?!login)|$)/),
    page.locator('button[type="submit"]').click(),
  ]);
}

test('manual category merchandising changes the real storefront product order and restores cleanly', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating merchandising contract runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  await page.goto('/admin/catalog/merchandising', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);

  const options = await page.locator('select[name="category"] option').evaluateAll((nodes) =>
    nodes.map((node) => ({
      value: (node as HTMLOptionElement).value,
      label: (node.textContent || '').trim(),
    })).filter((row) => row.value !== '')
  );

  let categoryId = '';
  let categoryName = '';
  for (const option of options) {
    await page.goto('/admin/catalog/merchandising?category=' + encodeURIComponent(option.value), { waitUntil: 'domcontentloaded' });
    if (await page.locator('[data-merch-list] [data-product-id]').count() >= 2) {
      categoryId = option.value;
      categoryName = option.label;
      break;
    }
  }
  test.skip(categoryId === '', 'Demo catalog has no category with at least two products.');

  const form = page.locator('form[data-merch-form]');
  const originalMode = await form.locator('select[name="mode"]').inputValue();
  const rows = form.locator('[data-merch-list] [data-product-id]');
  const originalIds = await rows.evaluateAll((nodes) => nodes.map((node) => (node as HTMLElement).dataset.productId || ''));
  const originalNames = await rows.evaluateAll((nodes) => nodes.map((node) => (node.querySelector('strong')?.textContent || '').trim()));
  expect(originalIds.length).toBeGreaterThanOrEqual(2);

  const changedIds = [...originalIds];
  [changedIds[0], changedIds[1]] = [changedIds[1], changedIds[0]];
  await form.locator('select[name="mode"]').selectOption('manual');
  await form.locator('input[data-merch-order]').evaluate((element, value) => {
    (element as HTMLInputElement).value = String(value);
  }, changedIds.join(','));

  const saveResponse = page.waitForResponse((response) =>
    response.url().includes('/admin/catalog/merchandising') && response.request().method() === 'POST'
  );
  await form.locator('button[type="submit"]').click();
  expect((await saveResponse).status()).toBeLessThan(400);
  await page.waitForLoadState('domcontentloaded');

  try {
    // The category may sit deep in the tree: walk down through the chips and the subcategory tiles like a shopper.
    const wanted = categoryName.replace(/^[\s—–\-·]+/, '').toLowerCase();
    const links = async (): Promise<{ text: string; href: string }[]> =>
      page.locator('.category-chips a, .category-tiles a').evaluateAll((nodes) =>
        nodes.map((node) => ({ text: (node.textContent || '').trim().toLowerCase(), href: (node as HTMLAnchorElement).getAttribute('href') || '' })));
    let categoryUrl: string | null = null;
    const queue: string[] = ['/catalog'];
    const visited = new Set<string>();
    while (queue.length && !categoryUrl && visited.size < 80) {
      const path = queue.shift()!;
      if (visited.has(path)) continue;
      visited.add(path);
      await page.goto(path, { waitUntil: 'domcontentloaded' });
      const found = await links();
      const hit = found.find((link) => link.text.includes(wanted));
      if (hit) { categoryUrl = hit.href; break; }
      for (const link of found) if (link.href && !visited.has(link.href)) queue.push(link.href);
    }
    expect(categoryUrl, 'the category is reachable from the catalogue').toBeTruthy();

    await page.goto(categoryUrl!, { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    const storefrontNames = await page.locator('[data-product-card] h2 a').evaluateAll((nodes) =>
      nodes.map((node) => (node.textContent || '').trim())
    );
    expect(storefrontNames.slice(0, 2)).toEqual([originalNames[1], originalNames[0]]);
  } finally {
    await page.goto('/admin/catalog/merchandising?category=' + encodeURIComponent(categoryId), { waitUntil: 'domcontentloaded' });
    const restore = page.locator('form[data-merch-form]');
    await restore.locator('select[name="mode"]').selectOption(originalMode);
    await restore.locator('input[data-merch-order]').evaluate((element, value) => {
      (element as HTMLInputElement).value = String(value);
    }, originalIds.join(','));
    const restoreResponse = page.waitForResponse((response) =>
      response.url().includes('/admin/catalog/merchandising') && response.request().method() === 'POST'
    );
    await restore.locator('button[type="submit"]').click();
    expect((await restoreResponse).status()).toBeLessThan(400);
  }
});
