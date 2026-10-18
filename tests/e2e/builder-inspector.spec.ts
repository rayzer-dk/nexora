import { expect, test, type Locator, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

const BADGE = 'extension.example_product_badge.badge';

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

async function postLayout(page: Page, type: string, layoutJson: string, action: 'draft' | 'publish'): Promise<void> {
  await page.goto(`/admin/appearance/builder/${type}`, { waitUntil: 'domcontentloaded' });
  const token = await page.locator('#builder-form input[name="_csrf_token"]').inputValue();
  const response = await page.request.post(`/admin/appearance/builder/${type}`, { maxRedirects: 0, form: { _csrf_token: token, layout_json: layoutJson, builder_action: action } });
  expect(response.status()).toBeGreaterThanOrEqual(300);
  expect(response.status()).toBeLessThan(400);
}

/** builder.js loads lazily; its first render adds the device class (is-desktop) to the preview canvas. */
async function openBuilder(page: Page, type: string): Promise<void> {
  await page.goto(`/admin/appearance/builder/${type}`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-preview-canvas][class*="is-"]')).toHaveCount(1);
}

async function addBlock(page: Page, component: string): Promise<void> {
  await page.locator('[data-add-component]').selectOption(component);
  await page.locator('[data-add-block]').click();
}

async function saveDraft(page: Page): Promise<void> {
  await Promise.all([page.waitForURL(/\/admin\/appearance\/builder\//), page.locator('button[form="builder-form"][value="draft"]').click()]);
  await expectNoServerError(page);
  await expect(page.locator('[data-preview-canvas][class*="is-"]')).toHaveCount(1);
}

const field = (inspector: Locator, prop: string): Locator => inspector.locator(`[data-field="${prop}"]`).first();

async function setModuleStatus(page: Page, active: boolean): Promise<boolean> {
  await page.goto('/admin/system/extensions', { waitUntil: 'domcontentloaded' });
  const row = page.locator('table.admin-table tbody tr').filter({ hasText: 'example.product_badge' }).first();
  if ((await row.count()) === 0) return false;
  const form = row.locator(`form[action$="/${active ? 'activate' : 'disable'}"]`);
  if ((await form.count()) === 0) return true;
  await form.locator('button[type="submit"]').click();
  await page.locator('[data-admin-confirm] [data-confirm-accept]').click();
  await page.waitForLoadState('domcontentloaded');
  await expectNoServerError(page);
  return true;
}

test('banner fields: typed controls, invalid values held back, values kept after reopening', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating builder check runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await loginAdmin(page);
  await openBuilder(page, 'home');
  const original = await page.locator('textarea[name="layout_json"]').inputValue();

  try {
    await addBlock(page, 'banner');
    const inspector = page.locator('[data-inspector]');
    await expect(inspector.locator('[data-builder-fields-warning]')).toHaveCount(0);
    await expect(field(inspector, 'props.width')).toHaveJSProperty('tagName', 'SELECT');
    await expect(field(inspector, 'props.overlay')).toHaveAttribute('type', 'number');
    await expect(field(inspector, 'props.overlay')).toHaveAttribute('max', '80');
    await expect(inspector.locator('input[type="color"][data-color-for="style.background"]')).toHaveCount(1);
    await expect(inspector.locator('[data-open-media="props.image_mobile"]')).toHaveCount(1);
    await expect(inspector.locator('.mc-pos-grid input[data-field="props.position"]')).toHaveCount(9);

    await field(inspector, 'props.title').fill('Inspector banner');
    await field(inspector, 'props.width').selectOption('half');
    await field(inspector, 'props.overlay').fill('40');
    await field(inspector, 'style.background').fill('#123456');
    await inspector.locator('input[data-field="visibility.mobile"]').uncheck();

    await field(inspector, 'props.button_url').fill('javascript:alert(1)');
    await expect(inspector.locator('[data-error-for="props.button_url"]')).toBeVisible();
    await field(inspector, 'props.overlay').fill('95');
    await expect(inspector.locator('[data-error-for="props.overlay"]')).toBeVisible();
    const stored = JSON.parse(await page.locator('textarea[name="layout_json"]').inputValue());
    const banner = stored.blocks.at(-1);
    expect(banner.props.button_url).toBeUndefined();
    expect(banner.props.overlay).toBe(40);

    // A wrong field stops the save in the browser.
    await page.locator('button[form="builder-form"][value="draft"]').click();
    await expect(inspector.locator('[data-builder-invalid]')).toBeVisible();
    await expect(page).toHaveURL(/\/admin\/appearance\/builder\/home$/);

    await field(inspector, 'props.button_url').fill('/sale');
    await field(inspector, 'props.overlay').fill('40');
    await saveDraft(page);

    const saved = JSON.parse(await page.locator('textarea[name="layout_json"]').inputValue());
    const reopened = saved.blocks.find((block: { props: { title?: string } }) => block.props.title === 'Inspector banner');
    expect(reopened).toBeTruthy();
    expect(reopened.props).toMatchObject({ width: 'half', overlay: 40, button_url: '/sale' });
    expect(reopened.style.background).toBe('#123456');
    expect(reopened.visibility.mobile).toBe(false);
    await page.locator(`[data-select="${reopened.id}"]`).click();
    await expect(field(page.locator('[data-inspector]'), 'props.width')).toHaveValue('half');

    // The server checks again: a wrong value posted past the browser is refused.
    reopened.props.button_url = 'javascript:alert(1)';
    const token = await page.locator('#builder-form input[name="_csrf_token"]').inputValue();
    await page.request.post('/admin/appearance/builder/home', { maxRedirects: 0, form: { _csrf_token: token, layout_json: JSON.stringify(saved), builder_action: 'draft' } });
    await openBuilder(page, 'home');
    const after = JSON.parse(await page.locator('textarea[name="layout_json"]').inputValue());
    expect(after.blocks.find((block: { id: string }) => block.id === reopened.id).props.button_url).toBe('/sale');
  } finally {
    await postLayout(page, 'home', original, 'draft');
  }
});

test('module block: fields from its settings_schema are saved and reach the product page', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating builder check runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  await page.goto('/catalog', { waitUntil: 'domcontentloaded' });
  const productUrl = await page.locator('[data-product-card] h2 a').first().getAttribute('href');
  test.skip(!productUrl, 'The store has no product.');

  await loginAdmin(page);
  test.skip(!(await setModuleStatus(page, true)), 'The example module docs/examples/extensions/product-badge is not installed.');
  await openBuilder(page, 'product');
  const original = await page.locator('textarea[name="layout_json"]').inputValue();

  try {
    await addBlock(page, BADGE);
    const inspector = page.locator('[data-inspector]');
    await expect(inspector.locator('[data-builder-fields-warning]')).toHaveCount(0);
    // The panel follows the schema the installed version declares (the docs example has a plain title, newer builds a per-language one).
    const builder = page.locator('[data-builder]');
    const fields: { prop: string; type: string; label: string }[] = JSON.parse((await builder.getAttribute('data-builder-fields'))!)[BADGE];
    const locales: { code: string }[] = JSON.parse((await builder.getAttribute('data-builder-locales'))!);
    const title = fields.find((item) => item.prop === 'props.title');
    expect(title, 'the module schema declares a title field').toBeTruthy();
    await expect(inspector).toContainText(title!.label);
    const shown = 'Inspector badge';
    let expected: unknown = shown;
    if (title!.type === 'multilingual_text') {
      await expect(inspector.locator('[data-field="props.title"][data-locale]')).toHaveCount(locales.length);
      await inspector.locator(`[data-field="props.title"][data-locale="${locales[0].code}"]`).fill(shown);
      expected = { [locales[0].code]: shown };
    } else {
      await field(inspector, 'props.title').fill(shown);
    }
    await saveDraft(page);

    const saved = JSON.parse(await page.locator('textarea[name="layout_json"]').inputValue());
    const badge = saved.blocks.find((block: { component: string }) => block.component === BADGE);
    expect(badge.props.title).toEqual(expected);
    await page.locator(`[data-select="${badge.id}"]`).click();
    await expect(field(page.locator('[data-inspector]'), 'props.title')).toHaveValue(shown);

    await postLayout(page, 'product', JSON.stringify(saved), 'publish');
    await page.goto(productUrl!, { waitUntil: 'domcontentloaded' });
    // The module also fills its after-price slot with the same component; the builder block is the one with our text.
    await expect(page.locator(`[data-extension-component="${BADGE}"]`).filter({ hasText: shown })).toHaveCount(1);
  } finally {
    await postLayout(page, 'product', original, 'publish');
    await postLayout(page, 'product', original, 'draft');
    await setModuleStatus(page, false);
  }
});
