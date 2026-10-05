import { expect, test } from '@playwright/test';
import { expectNoServerError } from './helpers';

test('the free-delivery progress bar shows in the cart, the slide-in cart and the checkout and follows the quantity', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating delivery settings runs once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);

  // Every delivery method asks a fee and is free over a high amount, so the shop has a free-delivery threshold.
  await page.goto('/admin/shipments/methods', { waitUntil: 'domcontentloaded' });
  const original = await page.evaluate(() =>
    Array.from(document.querySelectorAll<HTMLInputElement>('input[name^="cfg["]'))
      .filter((i) => /\]\[(fee|free_over)\]$/.test(i.name))
      .map((i) => [i.name, i.value] as [string, string]),
  );
  const setValues = async (values: Array<[string, string]>) => {
    await page.goto('/admin/shipments/methods', { waitUntil: 'domcontentloaded' });
    await page.evaluate((v) => {
      for (const [name, value] of v) {
        const input = document.querySelector<HTMLInputElement>(`input[name="${name}"]`);
        if (input) input.value = value;
      }
    }, values);
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form:has(input[name^="cfg["]) .admin-form-actions button[type="submit"]').click()]);
    await expectNoServerError(page);
  };
  const withThreshold = original.map(([name, value]): [string, string] => [name, name.endsWith('[fee]') ? '50' : name.endsWith('[free_over]') ? '1000000' : value]);

  try {
    await setValues(withThreshold);

    await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
    const card = page.locator('[data-product-card]').filter({ has: page.locator('form[data-card-add-to-cart]') }).first();
    const added = page.waitForResponse((r) => r.url().endsWith('/cart/add') && r.request().method() === 'POST');
    await card.locator('form[data-card-add-to-cart] button[type="submit"]').click();
    expect((await added).status()).toBeLessThan(500);

    await page.goto('/cart', { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    const bar = page.locator('[data-free-shipping]');
    await expect(bar).toBeVisible();
    const before = await page.locator('[data-free-shipping-text]').innerText();
    expect(before).toMatch(/\d/);

    // More goods: the bar updates without a reload.
    await page.locator('[data-cart-plus]').first().click();
    await expect(page.locator('[data-free-shipping-text]')).not.toHaveText(before);

    const drawer = await (await page.request.get('/cart/drawer')).text();
    expect(drawer).toContain('data-free-shipping');

    await page.goto('/checkout', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.order-summary [data-free-shipping]')).toBeVisible();
  } finally {
    await setValues(original);
  }
});
