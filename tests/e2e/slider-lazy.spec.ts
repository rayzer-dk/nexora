import { test, expect } from '@playwright/test';

for (const script of ['assets/storefront/slider.js', 'public/assets/slider.js']) {
  test(`carousel initializes near the viewport (${script})`, async ({ page }) => {
    const slider = (id: string, loop = '') => `
      <commerce-slider id="${id}" ${loop}>
        <button data-slider-prev>Previous</button>
        <button data-slider-next>Next</button>
        <div data-slider-track>
          <div>One</div><div>Two</div><div>Three</div>
        </div>
      </commerce-slider>`;
    await page.setContent(`
      <style>
        commerce-slider { display: block; width: 240px; }
        [data-slider-track] { display: flex; overflow-x: auto; width: 240px; }
        [data-slider-track] > div { flex: 0 0 180px; height: 80px; }
      </style>
      ${slider('visible')}
      ${slider('plain', 'data-loop="off"')}
      <div style="height: 3000px"></div>
      ${slider('distant')}
    `);
    await page.addScriptTag({ path: script });
    await expect(page.locator('#visible [data-slider-clone]')).toHaveCount(6);
    await expect(page.locator('#distant [data-slider-clone]')).toHaveCount(0);
    await expect(page.locator('#plain [data-slider-clone]')).toHaveCount(0);
    await page.locator('#distant').scrollIntoViewIfNeeded();
    await expect(page.locator('#distant [data-slider-clone]')).toHaveCount(6);
    await expect(page.locator('#distant [data-slider-clone][inert][aria-hidden="true"]')).toHaveCount(6);
    await page.locator('#plain [data-slider-next]').click();
    await expect(page.locator('#plain [data-slider-clone]')).toHaveCount(0);
  });
}
