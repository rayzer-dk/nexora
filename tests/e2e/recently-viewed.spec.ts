import { expect, test, type Page } from '@playwright/test';

type Recent = { id: string; name: string; url: string; image: string };

async function storedHistory(page: Page): Promise<Recent[]> {
  return page.evaluate(() => JSON.parse(localStorage.getItem('mc_recent') || '[]') as Recent[]);
}

test('recently viewed uses bounded, consent-gated localStorage with public references only', async ({ page }) => {
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-recent-list]')).toBeHidden();
  expect(await storedHistory(page)).toEqual([]);

  await page.locator('[data-consent-accept-all]').click();
  await expect(page.locator('[data-commerce-consent]')).toBeHidden();

  const hrefs = await page.locator('[data-product-card] a.catalog-card__media').evaluateAll((els) =>
    [...new Set(els.map((el) => (el as HTMLAnchorElement).getAttribute('href') ?? ''))].filter((h) => h.startsWith('/')).slice(0, 2));
  expect(hrefs.length).toBe(2);

  await page.goto(hrefs[0], { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-recent-list]')).toBeHidden();
  await page.goto(hrefs[1], { waitUntil: 'domcontentloaded' });
  const list = page.locator('[data-recent-list]');
  await expect(list).toBeVisible();
  await expect(list.locator('li')).toHaveCount(1);
  await expect(list.locator('li a')).toHaveAttribute('href', hrefs[0]);

  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-recent-list] li')).toHaveCount(2);
  const stored = await storedHistory(page);
  expect(stored).toHaveLength(2);
  expect(stored.every((item) => Object.keys(item).sort().join(',') === 'id,image,name,url')).toBe(true);
  expect(await page.evaluate(async () => (await indexedDB.databases()).some((db) => db.name === 'nexora-public-ui-v1'))).toBe(false);

  await page.locator('[data-consent-open]').first().click();
  await page.locator('[data-consent-reject-optional]').click();
  await expect(page.locator('[data-recent-list]')).toBeHidden();
  await expect.poll(() => storedHistory(page)).toEqual([]);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-recent-list]')).toBeHidden();
});

test('old IndexedDB history migrates to localStorage without losing public products', async ({ page }) => {
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await page.locator('[data-consent-accept-all]').click();
  await expect(page.locator('[data-commerce-consent]')).toBeHidden();
  await page.evaluate(() => new Promise<void>((resolve, reject) => {
    const request = indexedDB.open('nexora-public-ui-v1', 1);
    request.onupgradeneeded = () => request.result.createObjectStore('recently-viewed');
    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
      const db = request.result;
      const tx = db.transaction('recently-viewed', 'readwrite');
      tx.objectStore('recently-viewed').put([
        { id: 'legacy-iphone', name: 'Legacy phone', url: '/iphone-x', image: '/assets/branding/nexora-mark-192.png' },
      ], 'products');
      tx.oncomplete = () => { db.close(); resolve(); };
      tx.onerror = () => { db.close(); reject(tx.error); };
    };
  }));
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect.poll(() => storedHistory(page)).toEqual([
    { id: 'legacy-iphone', name: 'Legacy phone', url: '/iphone-x', image: '/assets/branding/nexora-mark-192.png' },
  ]);
  await expect.poll(() => page.evaluate(async () =>
    (await indexedDB.databases()).some((db) => db.name === 'nexora-public-ui-v1')
  )).toBe(false);
});
