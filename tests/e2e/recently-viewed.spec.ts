import { expect, test, type Page } from '@playwright/test';

async function storedHistory(page: Page): Promise<Array<{ id: string; name: string; url: string; image: string }> | null> {
  return page.evaluate(async () => new Promise((resolve) => {
    const request = indexedDB.open('nexora-public-ui-v1', 1);
    request.onerror = () => resolve(null);
    request.onsuccess = () => {
      const db = request.result;
      try {
        const transaction = db.transaction('recently-viewed', 'readonly');
        const record = transaction.objectStore('recently-viewed').get('products');
        record.onsuccess = () => { resolve(Array.isArray(record.result) ? record.result : null); db.close(); };
        record.onerror = () => { resolve(null); db.close(); };
      } catch { resolve(null); db.close(); }
    };
  }));
}


test('recently viewed block lists visited products, excludes the current one and stays client-side', async ({ page }) => {
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-recent-list]')).toBeHidden();
  // personalisation storage requires "Preferences" consent
  await page.locator('[data-consent-accept-all]').click();
  await expect(page.locator('[data-commerce-consent]')).toBeHidden();

  const hrefs = await page.locator('[data-product-card] a.catalog-card__media').evaluateAll((els) =>
    [...new Set(els.map((el) => (el as HTMLAnchorElement).getAttribute('href') ?? ''))].filter((h) => h.startsWith('/')).slice(0, 2));
  expect(hrefs.length).toBe(2);

  await page.goto(hrefs[0], { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-recent-list]')).toBeHidden(); // only the current product so far
  await page.goto(hrefs[1], { waitUntil: 'domcontentloaded' });
  const list = page.locator('[data-recent-list]');
  await expect(list).toBeVisible();
  await expect(list.locator('li')).toHaveCount(1);
  await expect(list.locator('li a')).toHaveAttribute('href', hrefs[0]);

  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-recent-list] li')).toHaveCount(2);
  const stored = await storedHistory(page);
  expect(stored).toHaveLength(2);
  expect(stored?.every((item) => Object.keys(item).sort().join(',') === 'id,image,name,url')).toBe(true);
  expect(await page.evaluate(() => localStorage.getItem('mc_recent'))).toBeNull();

  // Withdrawing consent removes client-side personalization data without a reload.
  await page.locator('[data-consent-open]').first().click();
  await page.locator('[data-consent-reject-optional]').click();
  await expect(page.locator('[data-recent-list]')).toBeHidden();
  await expect.poll(() => storedHistory(page)).toBeNull();
  expect(await page.evaluate(() => localStorage.getItem('mc_recent'))).toBeNull();
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-recent-list]')).toBeHidden();
});
