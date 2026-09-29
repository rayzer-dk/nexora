import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0 });

const CODE = 'acme.badge';
const NAME = 'Acme Badge';
const work = path.resolve('var/e2e-authoring');

function console_(...args: string[]): string {
  return execFileSync('php', ['bin/console', ...args, '--no-interaction'], { encoding: 'utf8', env: process.env });
}

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/|$)/), page.locator('button[type="submit"]').click()]);
}

const row = (page: Page, version: string) =>
  page.locator('table.admin-table tbody tr').filter({ hasText: NAME }).filter({ has: page.locator(`strong:text-is("${version}")`) });

async function post(page: Page, version: string, selector: string, confirm = true): Promise<void> {
  const button = row(page, version).locator(selector).first();
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/admin/system/extensions')),
    (async () => {
      await button.click();
      if (confirm) {
        await expect(page.locator('[data-admin-confirm]')).toBeVisible();
        await page.locator('[data-admin-confirm] [data-confirm-accept]').click();
      }
    })(),
  ]);
  await page.waitForLoadState('domcontentloaded');
  await expectNoServerError(page);
}

test('module authoring lifecycle: scaffold, pack, install, activate, configure, update, roll back, disable and uninstall', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating extension audit runs once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');

  rmSync(work, { recursive: true, force: true });
  mkdirSync(work, { recursive: true });
  const scaffold = path.join(work, 'src');
  console_('commerce:extension:scaffold', CODE, `--name=${NAME}`, '--preset=product-block', `--target=${path.relative(process.cwd(), scaffold)}`);
  const dir = path.join(scaffold, CODE);
  const v1 = path.join(work, 'acme.badge-1.0.0.zip');
  expect(console_('commerce:extension:pack', dir, `--output=${v1}`)).toContain('Package created');
  expect(console_('commerce:extension:test', v1)).not.toMatch(/FAIL/);

  const manifestPath = path.join(dir, 'manifest.json');
  const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
  manifest.version = '1.1.0';
  writeFileSync(manifestPath, JSON.stringify(manifest, null, 2));
  const v2 = path.join(work, 'acme.badge-1.1.0.zip');
  console_('commerce:extension:pack', dir, `--output=${v2}`);

  await loginAdmin(page);
  const upload = async (file: string) => {
    await page.goto('/admin/system/extensions', { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="extension_package"]').setInputFiles(file);
    await Promise.all([
      page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/admin/system/extensions')),
      page.locator('form.admin-extension-upload button[type="submit"]').click(),
    ]);
    await page.waitForLoadState('domcontentloaded');
    await expectNoServerError(page);
  };

  try {
    // Install + activate 1.0.0.
    await upload(v1);
    await expect(row(page, '1.0.0')).toContainText('staged');
    await post(page, '1.0.0', 'form[action$="/activate"] button');
    await expect(row(page, '1.0.0')).toContainText('active');

    // The module contributes a Builder component and a settings page.
    await page.goto('/admin/appearance/builder/product', { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    expect(await page.content()).toContain('extension.acme_badge.product_panel');
    await row(page, '1.0.0').count();
    await page.goto('/admin/system/extensions', { waitUntil: 'domcontentloaded' });
    await row(page, '1.0.0').locator('a[href$="/settings"]').click();
    await expectNoServerError(page);
    await page.locator('input[name="settings[enabled_feature]"]').check();
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form[data-dirty-guard] button[type="submit"]').click()]);
    await expectNoServerError(page);

    // Use: place the module block on the product page through the Builder and see it on the storefront.
    await page.goto('/admin/appearance/builder/product', { waitUntil: 'domcontentloaded' });
    const layout = JSON.parse(await page.locator('[data-layout-json]').inputValue());
    layout.blocks.push({ id: 'acme_panel', component: 'extension.acme_badge.product_panel', enabled: true, props: { title: 'Acme trust badge', text: 'Free returns' }, style: [], visibility: [] });
    await page.locator('[data-layout-json]').evaluate((el, value) => { (el as HTMLTextAreaElement).value = value; }, JSON.stringify(layout));
    await Promise.all([
      page.waitForNavigation(),
      page.locator('form:has([data-layout-json])').evaluate((form: HTMLFormElement) => {
        const action = document.createElement('input');
        action.type = 'hidden'; action.name = 'builder_action'; action.value = 'publish';
        form.append(action);
        form.submit();
      }),
    ]);
    await expectNoServerError(page);
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const productHref = await page.locator('[data-product-card] a.catalog-card__media').first().getAttribute('href');
    await page.goto(productHref!, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-extension-component="extension.acme_badge.product_panel"]')).toContainText('Acme trust badge');

    // Update to 1.1.0.
    await upload(v2);
    await expect(row(page, '1.1.0')).toContainText('staged');
    await post(page, '1.1.0', 'form[action$="/activate"] button');
    await expect(row(page, '1.1.0')).toContainText('active');
    await expect(row(page, '1.0.0')).toContainText('disabled');

    // The active version cannot be uninstalled; the old one can.
    await expect(row(page, '1.1.0').locator('form[action$="/uninstall"]')).toHaveCount(0);

    // Roll back to 1.0.0, then disable and uninstall everything.
    await post(page, '1.1.0', 'form[action$="/rollback"] button');
    await expect(row(page, '1.0.0')).toContainText('active');
    await post(page, '1.0.0', 'form[action$="/disable"] button');
    await expect(row(page, '1.0.0')).toContainText('disabled');

    await post(page, '1.1.0', 'form[action$="/uninstall"] button');
    await expect(row(page, '1.1.0')).toHaveCount(0);
    await post(page, '1.0.0', 'form[action$="/uninstall"] button');
    await expect(page.locator('table.admin-table tbody tr').filter({ hasText: NAME }).filter({ has: page.locator('strong:text-is("1.0.0")') })).toHaveCount(0);
    await expect(page.locator('[data-toast-source], .admin-notice').first()).toBeAttached();
    expect(existsSync(path.resolve('var/extensions/installed', CODE))).toBe(false);

    const after = await page.goto(productHref!, { waitUntil: 'domcontentloaded' });
    expect(after?.status()).toBe(200);
    await expect(page.locator('[data-extension-component]')).toHaveCount(0);

    // The Builder no longer offers the component and a fresh install works again (clean removal).
    await page.goto('/admin/appearance/builder/product', { waitUntil: 'domcontentloaded' });
    // The saved layout still references the block: Builder warns instead of failing, the storefront skips it.
    await expect(page.locator('.admin-toast.is-warning')).toBeVisible();
    await expect(page.locator('[data-add-block] ~ *, select[data-component-select]').filter({ hasText: 'extension.acme_badge.product_panel' })).toHaveCount(0);
    await upload(v1);
    await expect(row(page, '1.0.0')).toContainText('staged');
    await post(page, '1.0.0', 'form[action$="/uninstall"] button');
  } finally {
    rmSync(work, { recursive: true, force: true });
  }
});

test('trusted PHP module: keygen, scaffold, pack, sign, install, activate, serve a route, uninstall', async ({ page, request }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating extension audit runs once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');

  const trustedFile = path.resolve('config/extensions/trusted-publishers.json');
  const originalTrusted = readFileSync(trustedFile, 'utf8');
  const dirWork = path.resolve('var/e2e-trusted');
  rmSync(dirWork, { recursive: true, force: true });
  mkdirSync(dirWork, { recursive: true });
  const keyFile = path.join(dirWork, 'acme.e2e.key');
  try {
    const keygen = console_('commerce:extension:keygen', 'acme.e2e', `--out=${keyFile}`);
    const publicKey = /"public_key": "([^"]+)"/.exec(keygen)![1];
    const trusted = JSON.parse(originalTrusted);
    trusted.publishers['acme.e2e'] = { name: 'Acme E2E', public_key: publicKey };
    writeFileSync(trustedFile, JSON.stringify(trusted, null, 2));

    console_('commerce:extension:scaffold', 'acme.route', '--name=Acme Route', '--preset=trusted-route', '--execution=trusted_release', `--target=${path.relative(process.cwd(), dirWork)}/src`);
    const dir = path.join(dirWork, 'src', 'acme.route');
    const manifestPath = path.join(dir, 'manifest.json');
    const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
    manifest.publisher = { name: 'Acme E2E', key_id: 'acme.e2e' };
    manifest.signature.key_id = 'acme.e2e';
    writeFileSync(manifestPath, JSON.stringify(manifest, null, 2));
    const zip = path.join(dirWork, 'route.zip');
    console_('commerce:extension:pack', dir, `--output=${zip}`);
    console_('commerce:extension:sign', zip, keyFile);
    expect(console_('commerce:extension:validate', zip)).toContain('VALID');

    await loginAdmin(page);
    await page.goto('/admin/system/extensions', { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="extension_package"]').setInputFiles(zip);
    await Promise.all([
      page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/admin/system/extensions')),
      page.locator('form.admin-extension-upload button[type="submit"]').click(),
    ]);
    await page.waitForLoadState('domcontentloaded');
    const trow = page.locator('table.admin-table tbody tr').filter({ hasText: 'Acme Route' });
    await expect(trow).toContainText('staged');
    expect((await request.get('/acme-route/hello')).status()).toBe(404);

    await Promise.all([
      page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/activate')),
      (async () => {
        await trow.locator('form[action$="/activate"] button').click();
        await page.locator('[data-admin-confirm] [data-confirm-accept]').click();
      })(),
    ]);
    await page.waitForLoadState('domcontentloaded');
    await expect(trow).toContainText('active');
    const hello = await request.get('/acme-route/hello');
    expect(hello.status()).toBe(200);
    expect(await hello.text()).toBe('Hello from Acme Route');

    await Promise.all([
      page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/disable')),
      (async () => {
        await trow.locator('form[action$="/disable"] button').click();
        await page.locator('[data-admin-confirm] [data-confirm-accept]').click();
      })(),
    ]);
    await page.waitForLoadState('domcontentloaded');
    expect((await request.get('/acme-route/hello')).status()).toBe(404);
    await Promise.all([
      page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/uninstall')),
      (async () => {
        await trow.locator('form[action$="/uninstall"] button').click();
        await page.locator('[data-admin-confirm] [data-confirm-accept]').click();
      })(),
    ]);
    await page.waitForLoadState('domcontentloaded');
    await expectNoServerError(page);
    await expect(page.locator('table.admin-table tbody tr').filter({ hasText: 'Acme Route' })).toHaveCount(0);
    expect(existsSync(path.resolve('var/extensions/installed/acme.route'))).toBe(false);
  } finally {
    writeFileSync(trustedFile, originalTrusted);
    rmSync(dirWork, { recursive: true, force: true });
  }
});
