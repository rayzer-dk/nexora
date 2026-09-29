import { expect, test } from '@playwright/test';

test('recently viewed block lists visited products, excludes the current one and stays client-side', async ({ page }) => {
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-recent-list]')).toBeHidden();

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
});
