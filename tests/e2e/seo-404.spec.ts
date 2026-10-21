import { expect, test } from '@playwright/test';
import { expectNoServerError } from './helpers';

const human = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
const bot = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

test('404 log separates people from bots and creates redirects in one action; SEO template legend is per type', async ({ page, request }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings run once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  const stamp = Date.now();
  const humanPaths = [`/e2e-old-human-a-${stamp}`, `/e2e-old-human-b-${stamp}`];
  const botPath = `/e2e-old-bot-${stamp}`;
  for (const path of humanPaths) {
    await request.get(path, { headers: { 'user-agent': human } });
  }
  await request.get(botPath, { headers: { 'user-agent': bot } });

  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);

  const notFound = page.locator('[data-tab="notfound"]');
  const openLog = async (kind: string) => {
    await page.goto(`/admin/system/seo-custom-redirects?nf=e2e-old-&kind=${kind}`, { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    const tab = page.getByRole('tab').nth(1);
    if (await tab.count()) await tab.click();
  };

  await openLog('bots');
  await expect(notFound.locator('tbody tr', { hasText: botPath })).toHaveCount(1);
  await expect(notFound.locator('tbody tr', { hasText: humanPaths[0] })).toHaveCount(0);

  await openLog('humans');
  await expect(notFound.locator('tbody tr', { hasText: humanPaths[0] })).toHaveCount(1);
  await expect(notFound.locator('tbody tr', { hasText: botPath })).toHaveCount(0);

  const removeRedirects = async () => {
    await page.goto(`/admin/system/seo-custom-redirects?search=e2e-old-human-`, { waitUntil: 'domcontentloaded' });
    const ownTab = page.getByRole('tab').first();
    if (await ownTab.count()) await ownTab.click();
    const rows = page.locator('[data-tab="own"] tbody tr', { hasText: `-${stamp}` });
    for (let i = 0; i < humanPaths.length && (await rows.count()) > 0; i++) {
      await rows.first().locator('form[action$="/delete"] button[type="submit"]').click();
      await page.locator('[data-confirm-accept]').click();
      await page.waitForLoadState('domcontentloaded');
    }
  };

  try {
    for (const path of humanPaths) {
      await notFound.locator('tbody tr', { hasText: path }).locator('input[name="ids[]"]').check();
    }
    await notFound.locator('#nf-bulk input[name="target"]').fill('/');
    await notFound.locator('#nf-bulk button[type="submit"]').click();
    await page.locator('[data-confirm-accept]').click();
    await page.waitForLoadState('domcontentloaded');
    await expectNoServerError(page);
    for (const path of humanPaths) {
      const r = await request.get(path, { maxRedirects: 0, headers: { 'user-agent': human } });
      expect(r.status()).toBe(301);
    }
  } finally {
    await removeRedirects();
  }

  await page.goto('/admin/system/seo-templates', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);
  for (const type of ['article', 'page']) {
    const legend = page.locator(`[data-seo-type="${type}"] .seo-vars`);
    await expect(legend).toContainText('{name}');
    await expect(legend).not.toContainText('{price}');
    await expect(legend).not.toContainText('{brand}');
    await expect(legend).not.toContainText('{sku}');
  }
  await expect(page.locator('[data-seo-type="product"] .seo-vars')).toContainText('{sku}');
});
