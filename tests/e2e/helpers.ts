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
  // With a srcset the browser reports naturalWidth divided by the chosen density, so a tiny real picture (a 4 px fixture
  // chosen for a 960w candidate) reads as 0. decode() settles that: it resolves for a real picture and rejects for a broken one.
  const read = () => page.locator('img').evaluateAll(async (images) => {
    const broken: string[] = [];
    for (const image of images as HTMLImageElement[]) {
      if (!image.complete || image.naturalWidth !== 0) continue;
      try { await image.decode(); } catch { broken.push(image.getAttribute('src') || ''); }
    }
    return broken;
  });
  let broken = await read();
  for (let attempt = 0; attempt < 5 && broken.length > 0; attempt += 1) {
    await page.waitForTimeout(400);
    broken = await read();
  }
  expect(broken, `broken images: ${broken.join(', ')}`).toEqual([]);
}

export async function expectNoServerError(page: Page): Promise<void> {
  await expect(page.locator('body')).not.toContainText('500 Internal Server Error');
  await expect(page.locator('body')).not.toContainText('Ой! Произошла ошибка');
}
