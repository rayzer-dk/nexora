import { expect, type Page } from '@playwright/test';

export const publicRoutes = ['/', '/catalog', '/cart'];

export async function expectNoHorizontalOverflow(page: Page): Promise<void> {
  const metrics = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  expect(metrics.scrollWidth, `horizontal overflow: ${metrics.scrollWidth}px > ${metrics.clientWidth}px`).toBeLessThanOrEqual(metrics.clientWidth + 1);
}

export async function expectNoBrokenImages(page: Page): Promise<void> {
  const broken = await page.locator('img').evaluateAll((images) => images
    .filter((image) => image.complete && image.naturalWidth === 0)
    .map((image) => image.getAttribute('src') || ''));
  expect(broken, `broken images: ${broken.join(', ')}`).toEqual([]);
}

export async function expectNoServerError(page: Page): Promise<void> {
  await expect(page.locator('body')).not.toContainText('500 Internal Server Error');
  await expect(page.locator('body')).not.toContainText('Ой! Произошла ошибка');
}
