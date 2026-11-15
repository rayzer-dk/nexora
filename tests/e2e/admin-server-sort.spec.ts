import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError, loginAdmin } from './helpers';

// Lists paged on the server sort by a header on the server: the header sets ?sort=&dir= and the whole list is reordered,
// not only the visible page.
const lists = [
  '/admin/content/blog',
  '/admin/commerce/subscribers',
  '/admin/system/seo-redirects',
  '/admin/system/activity',
  '/admin/system/prro',
  '/admin/commerce/stock-requests',
  '/admin/catalog/quality',
];

async function sortBy(page: Page, key: string, dir: 'asc' | 'desc'): Promise<void> {
  const header = page.locator(`table.admin-table th[data-sort-key="${key}"]`).first();
  // A header that carries its own sort link (stock requests) is clicked on the link, which also works without the runtime.
  const link = header.locator('a[href*="sort="]');
  const target = (await link.count()) > 0 ? link : header;
  await Promise.all([
    page.waitForURL((url) => url.searchParams.get('sort') === key && url.searchParams.get('dir') === dir, { waitUntil: 'domcontentloaded' }),
    target.click(),
  ]);
  await expectNoServerError(page);
  await expect(header).toHaveAttribute('aria-sort', dir === 'asc' ? 'ascending' : 'descending');
}

test.describe('server-side sorting of paged admin lists', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once.');
    test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Needs E2E_ADMIN_EMAIL and E2E_ADMIN_PASSWORD.');
  });

  for (const path of lists) {
    test(`${path} sorts by a header on the server`, async ({ page }) => {
      await loginAdmin(page);
      await page.goto(path, { waitUntil: 'domcontentloaded' });
      await expectNoServerError(page);
      const header = page.locator('table.admin-table th[data-sort-key]').first();
      await expect(header).toBeAttached();
      const key = (await header.getAttribute('data-sort-key')) ?? '';
      // The runtime makes a header clickable only when the page shows at least two rows; with fewer rows (an empty
      // list in a fresh store) the server side is checked through the address the header would open.
      const clickable = (await header.evaluate((th) => th.classList.contains('is-sortable'))) || (await header.locator('a[href*="sort="]').count()) > 0;
      if (clickable) {
        await sortBy(page, key, 'asc');
        await sortBy(page, key, 'desc');
        return;
      }
      test.info().annotations.push({ type: 'note', description: `${path}: fewer than two rows, checked by address` });
      for (const dir of ['asc', 'desc']) {
        const response = await page.goto(`${path}?sort=${key}&dir=${dir}`, { waitUntil: 'domcontentloaded' });
        expect(response?.status()).toBe(200);
        await expectNoServerError(page);
        await expect(page.locator(`table.admin-table th[data-sort-key="${key}"]`).first()).toBeAttached();
      }
    });
  }
});
