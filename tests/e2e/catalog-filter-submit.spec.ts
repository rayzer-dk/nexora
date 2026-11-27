import { test, expect } from '@playwright/test';

test('catalog sort submits once and restores only temporarily disabled controls', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.clock.install();
  await page.setContent(`
    <form id="catalog-filters" data-catalog-filter-form>
      <select name="sort" data-filter-auto>
        <option value="newest">Newest</option>
        <option value="price">Price</option>
      </select>
      <input name="empty" value="">
      <input name="locked" value="keep" disabled>
    </form>
    <input form="catalog-filters" name="per_page" value="24">
    <output id="submissions">0</output>
  `);
  await page.addScriptTag({ path: 'assets/storefront/catalog-page.js' });
  await page.evaluate(() => {
    document.querySelector('form')?.addEventListener('submit', (event) => {
      event.preventDefault();
      const output = document.querySelector('#submissions');
      if (output) output.textContent = String(Number(output.textContent) + 1);
    });
  });
  await page.locator('select[name="sort"]').selectOption('price');
  await page.clock.fastForward(600);
  await expect(page.locator('#submissions')).toHaveText('1');
  await expect(page.locator('input[name="empty"]')).toBeDisabled();
  await expect(page.locator('input[name="per_page"]')).toBeDisabled();
  await page.evaluate(() => window.dispatchEvent(new Event('pageshow')));
  await expect(page.locator('input[name="empty"]')).toBeEnabled();
  await expect(page.locator('input[name="per_page"]')).toBeEnabled();
  await expect(page.locator('input[name="locked"]')).toBeDisabled();
});
