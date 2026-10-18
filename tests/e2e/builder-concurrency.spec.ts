import { expect, test, type Browser, type Page } from '@playwright/test';

test.describe.configure({ retries: 0, mode: 'serial' });

const builder = '/admin/appearance/builder/home';

async function openAs(browser: Browser, email: string, password: string): Promise<Page> {
  const page = await (await browser.newContext()).newPage();
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(email);
  await page.locator('input[name="_password"]').fill(password);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
  await page.goto(builder, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-builder-list] [data-block-id]').first()).toBeVisible();
  return page;
}

const draftAnswer = (page: Page, status: number) => page.waitForResponse((r) => r.url().endsWith('/draft.json') && r.status() === status, { timeout: 15_000 });

async function addHero(page: Page, title: string): Promise<void> {
  await page.locator('[data-add-component]').selectOption('hero');
  await page.locator('[data-add-block]').click();
  await page.locator('[data-inspector] [data-field="props.title"]').first().fill(title);
}

test('two editors of one page: presence, conflict, save over and reload', async ({ browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating builder check runs once.');
  const { E2E_ADMIN_EMAIL: emailA, E2E_ADMIN_PASSWORD: passwordA, E2E_ADMIN2_EMAIL: emailB, E2E_ADMIN2_PASSWORD: passwordB } = process.env;
  test.skip(!emailA || !passwordA || !emailB || !passwordB, 'Two administrator accounts are required (E2E_ADMIN_* and E2E_ADMIN2_*).');

  const a = await openAs(browser, emailA!, passwordA!);
  const b = await openAs(browser, emailB!, passwordB!);

  // B sees A at once; A sees B on its next report (every 30 s).
  await expect(b.locator('[data-builder-presence]')).toBeVisible({ timeout: 10_000 });
  await expect(a.locator('[data-builder-presence]')).toBeVisible({ timeout: 35_000 });

  // B saves first; A's autosave started from the older version and stops with B's name.
  const stamp = Date.now();
  const savedB = draftAnswer(b, 200);
  await addHero(b, `Редактор B ${stamp}`);
  await savedB;
  const refused = draftAnswer(a, 409);
  await addHero(a, `Редактор A ${stamp}`);
  await refused;
  await expect(a.locator('[data-builder-conflict]')).toBeVisible();
  await expect(a.locator('[data-builder-conflict] [data-conflict-text]')).toContainText(/\d{1,2}:\d{2}/);

  // "Save over" writes A's layout.
  const forced = draftAnswer(a, 200);
  await a.locator('[data-conflict-overwrite]').click();
  await forced;
  await expect(a.locator('[data-builder-conflict]')).toBeHidden();

  // Now B is behind: its next edit conflicts, and "Reload" (after confirming) loads A's version.
  const refusedB = draftAnswer(b, 409);
  await b.locator('[data-inspector] [data-field="props.title"]').first().fill(`Редактор B ${stamp} ще раз`);
  await refusedB;
  b.once('dialog', (dialog) => void dialog.accept());
  await Promise.all([b.waitForEvent('load'), b.locator('[data-conflict-reload]').click()]);
  await expect(b.locator('[data-builder-list]')).toContainText(`Редактор A ${stamp}`);
  await expect(b.locator('[data-builder-list]')).not.toContainText(`Редактор B ${stamp}`);
  await expect(b.locator('[data-builder-conflict]')).toBeHidden();

  // A puts the draft back as it was opened, then leaves: B's notice goes away.
  const restored = draftAnswer(a, 200);
  const undo = a.locator('[data-builder-undo]');
  while (await undo.isEnabled()) await undo.click();
  await restored;
  await expect(a.locator('[data-builder-list]')).not.toContainText(`Редактор A ${stamp}`);

  // B's page is behind A's last save: publishing and restoring from history are refused too, nothing is published.
  const refusedPublish = b.waitForResponse((r) => r.url().endsWith('/publish.json') && r.status() === 409, { timeout: 15_000 });
  await b.getByRole('button', { name: /Опублікувати|Publish/ }).click();
  await refusedPublish;
  await expect(b.locator('[data-builder-conflict]')).toBeVisible();
  const rollback = b.locator('tr:not(:has(.is-draft)) form[action*="/rollback/"]').first();
  const answer = await b.request.post(await rollback.getAttribute('action') as string, {
    form: { _csrf_token: await rollback.locator('[name="_csrf_token"]').inputValue(), base_version: await rollback.locator('[name="base_version"]').inputValue() },
    maxRedirects: 0,
  });
  expect(answer.status()).toBe(302);
  b.once('dialog', (dialog) => void dialog.accept());
  await Promise.all([b.waitForEvent('load'), b.locator('[data-conflict-reload]').click()]);
  await expect(b.locator('.admin-notice.is-error')).toContainText(/відновлення не виконано|version was not restored/);

  await a.goto('/admin', { waitUntil: 'domcontentloaded' });
  await expect(b.locator('[data-builder-presence]')).toBeHidden({ timeout: 40_000 });

  await a.context().close();
  await b.context().close();
});
