import { expect, test, type Page } from '@playwright/test';
import { loginAdmin } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

const LOGO = '/assets/branding/nexora-logo.png';

type Brand = { logo: string; showTitle: boolean; titles: Record<string, string>; height: string };

async function readBrand(page: Page): Promise<Brand> {
  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  return page.evaluate(() => {
    const form = document.querySelector('form[data-appearance-media]') as HTMLFormElement;
    const titles: Record<string, string> = {};
    form.querySelectorAll<HTMLInputElement>('[name^="brand_title["]').forEach((input) => { titles[input.name] = input.value; });
    return {
      logo: (form.elements.namedItem('brand_logo') as HTMLInputElement).value,
      showTitle: (form.elements.namedItem('brand_show_title') as HTMLInputElement).checked,
      titles,
      height: (form.elements.namedItem('brand_logo_height') as HTMLInputElement | null)?.value ?? '',
    };
  });
}

async function writeBrand(page: Page, brand: Brand): Promise<void> {
  await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
  await page.evaluate((b) => {
    const form = document.querySelector('form[data-appearance-media]') as HTMLFormElement;
    form.noValidate = true; // the server clamp is under test, not the browser's min/max check
    (form.elements.namedItem('brand_logo') as HTMLInputElement).value = b.logo;
    (form.elements.namedItem('brand_show_title') as HTMLInputElement).checked = b.showTitle;
    for (const [name, value] of Object.entries(b.titles)) (form.elements.namedItem(name) as HTMLInputElement).value = value;
    const height = form.elements.namedItem('brand_logo_height') as HTMLInputElement | null;
    if (height && b.height) height.value = b.height;
  }, brand);
  await Promise.all([page.waitForURL(/appearance\/storefront/), page.locator('form[data-appearance-media] button[type="submit"]').first().click()]);
}

async function visibleChrome(page: Page): Promise<string> {
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  const header = await page.locator('header.reference-header').innerText();
  const footer = await page.locator('footer.site-footer').innerText();
  return `${header}\n${footer}`;
}

test('shop name is hidden when only the logo is wanted, logo height is configurable and hinted', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings run once.');
  await loginAdmin(page);
  const original = await readBrand(page);
  const emptyTitles = Object.fromEntries(Object.keys(original.titles).map((name) => [name, '']));
  try {
    // logo set, "show name" off, name filled
    await writeBrand(page, { logo: LOGO, showTitle: false, titles: Object.fromEntries(Object.keys(original.titles).map((name) => [name, 'Zeta Brandname'])), height: '48' });
    await page.setViewportSize({ width: 1440, height: 1000 });
    let text = await visibleChrome(page);
    expect(text).not.toContain('Zeta Brandname');
    await expect(page.locator('.reference-brand__logo')).toHaveAttribute('alt', 'Zeta Brandname');
    await expect(page.locator('.reference-brand')).toHaveAttribute('aria-label', /Zeta Brandname/);
    await expect(page.locator('meta[property="og:site_name"]')).toHaveCount(1);
    await expect(page.locator('.reference-brand strong')).toHaveCount(0);

    // logo set, "show name" on, empty "Brand name": still logo only, the store name stays in alt
    await writeBrand(page, { logo: LOGO, showTitle: true, titles: emptyTitles, height: '48' });
    text = await visibleChrome(page);
    const storeName = (await page.locator('.reference-brand__logo').getAttribute('alt')) ?? '';
    expect(storeName.length).toBeGreaterThan(0);
    expect(text).not.toContain(storeName);
    expect(text.replace(/\S+@\S+/g, '').toLowerCase()).not.toContain('nexora');
    await expect(page.locator('footer.site-footer .brand--footer strong')).toHaveCount(0);

    // checkout has its own quiet footer: the name must not be there either
    await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
    const addResponse = page.waitForResponse((r) => r.url().endsWith('/cart/add') && r.request().method() === 'POST');
    await page.locator('[data-product-card]').filter({ has: page.locator('form[data-card-add-to-cart]') }).first().locator('form[data-card-add-to-cart] button[type="submit"]').click();
    expect((await addResponse).status()).toBeLessThan(500);
    await page.goto('/checkout', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-checkout]')).toHaveCount(1);
    expect(await page.locator('footer.checkout-footer').allInnerTexts()).not.toContain(storeName);
    expect(await page.locator('body').innerText()).not.toContain(`\n${storeName}\n`);

    // mobile: no name, no subtitle line, no overflow
    await page.setViewportSize({ width: 390, height: 844 });
    text = await visibleChrome(page);
    expect(text).not.toContain(storeName);
    await expect(page.locator('.reference-brand small')).toBeHidden();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);

    // default height 48, width up to 260
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const logo = page.locator('.reference-brand__logo');
    expect(await logo.evaluate((el) => getComputedStyle(el).height)).toBe('48px');
    expect(await logo.evaluate((el) => getComputedStyle(el).maxWidth)).toBe('260px');

    // custom height is saved and applied
    await writeBrand(page, { logo: LOGO, showTitle: true, titles: emptyTitles, height: '72' });
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    expect(await page.locator('.reference-brand__logo').evaluate((el) => getComputedStyle(el).height)).toBe('72px');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);
    const mobileHeight = await page.locator('.reference-brand__logo').evaluate((el) => el.getBoundingClientRect().height);
    expect(mobileHeight).toBeLessThanOrEqual(48);

    // out-of-range height is clamped to 32..96
    await writeBrand(page, { logo: LOGO, showTitle: true, titles: emptyTitles, height: '500' });
    expect((await readBrand(page)).height).toBe('96');
    await writeBrand(page, { logo: LOGO, showTitle: true, titles: emptyTitles, height: '5' });
    expect((await readBrand(page)).height).toBe('32');

    // no logo + empty name: the store name is shown so the header is not empty
    await writeBrand(page, { logo: '', showTitle: true, titles: emptyTitles, height: '48' });
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('header.reference-header .reference-brand strong')).toHaveText(storeName);

    // the hint next to the logo field names the size and the current height
    await page.goto('/admin/appearance/storefront', { waitUntil: 'domcontentloaded' });
    const field = page.locator('.admin-media-field:has([name="brand_logo"])');
    await expect(field).toContainText('520×130');
    await expect(field).toContainText('48');
    await expect(page.locator('input[name="brand_logo_height"]')).toHaveAttribute('min', '32');
    await expect(page.locator('input[name="brand_logo_height"]')).toHaveAttribute('max', '96');
  } finally {
    await writeBrand(page, original);
  }
});
