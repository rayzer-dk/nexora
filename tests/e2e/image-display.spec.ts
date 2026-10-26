import { expect, test, type Page } from '@playwright/test';
import { loginAdmin } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

test.beforeEach(({}, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Changes a shared setting once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');
});

async function save(page: Page, choices: Record<string, string>): Promise<void> {
  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  for (const [name, value] of Object.entries(choices)) {
    await page.locator(`label.admin-choice:has(input[name="${name}"][value="${value}"])`).click();
  }
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form:has(input[name="display_image_fit"]) button[type="submit"]').first().click()]);
}

/** Pretends the first photo on the catalogue is a small 100 x 60 original and returns the size the frame gives it. */
async function smallPhotoSize(page: Page): Promise<{ width: number; height: number; background: string }> {
  await page.goto('/catalog', { waitUntil: 'load' });
  return page.locator('.catalog-card__media:has(img[src*="/media/"])').first().evaluate((frame) => {
    const img = frame.querySelector('img') as HTMLImageElement;
    img.style.setProperty('--iw', '100');
    img.style.setProperty('--ih', '60');
    const box = img.getBoundingClientRect();

    return { width: Math.round(box.width), height: Math.round(box.height), background: getComputedStyle(frame).backgroundColor };
  });
}

test('photo scaling and background are set in the admin and drawn on the storefront without touching the files', async ({ page }) => {
  await loginAdmin(page);
  try {
    await save(page, { display_image_background: 'none', display_image_fit: 'contain', display_image_upscale: 'none' });
    expect(await smallPhotoSize(page)).toMatchObject({ width: 100, height: 60 });

    await save(page, { display_image_upscale: '2' });
    expect(await smallPhotoSize(page)).toMatchObject({ width: 200, height: 120 });

    await save(page, { display_image_upscale: 'unlimited', display_image_background: 'dark' });
    const dark = await smallPhotoSize(page);
    expect(dark.width).toBeGreaterThan(200);
    expect(dark.background).toBe('rgb(17, 24, 39)');
    await expect(page.locator('body')).toHaveAttribute('data-image-bg', 'dark');
  } finally {
    await save(page, { display_image_background: 'none', display_image_fit: 'contain', display_image_upscale: '3' });
  }
  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('body')).toHaveAttribute('style', /--mc-up-cards:3;/);
  await expect(page.locator('body')).toHaveAttribute('data-image-bg', 'none');
});
