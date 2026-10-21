import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { loginAdmin } from './helpers';

const shotsDir = process.env.LOOK_SHOTS_DIR ?? '';
const viewports = [
  { name: '1440', width: 1440, height: 1000 },
  { name: '390', width: 390, height: 844 },
] as const;
const schemes = ['light', 'dark'] as const;

async function expectLookIsClean(page: Page, label: string, path: string, scheme: (typeof schemes)[number]): Promise<void> {
  await page.goto(path, { waitUntil: 'domcontentloaded' });
  await page.evaluate((value) => localStorage.setItem('mc_theme', value), scheme);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.locator('html')).toHaveAttribute('data-theme', scheme);
  await page.waitForLoadState('networkidle').catch(() => undefined);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow, `${label}: horizontal scroll`).toBeLessThanOrEqual(0);
  const results = await new AxeBuilder({ page }).withRules(['color-contrast']).analyze();
  const failed = results.violations.flatMap((v) => v.nodes.map((n) => `${n.target.join(' ')} ratio ${(n.any[0]?.data as { contrastRatio?: number } | undefined)?.contrastRatio}`));
  expect(failed, `${label}: contrast`).toEqual([]);
  if (shotsDir) {
    mkdirSync(shotsDir, { recursive: true });
    await page.addStyleTag({ content: '.mc-consent{display:none!important}' });
    await page.screenshot({ path: join(shotsDir, `${label}.png`), fullPage: true });
  }
}

async function setMode(page: Page, mode: string): Promise<void> {
  await page.goto('/admin/system/site', { waitUntil: 'domcontentloaded' });
  await page.locator(`input[name="mode"][value="${mode}"]`).check({ force: true });
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form[data-site-mode-profiles] button[type="submit"]').first().click()]);
}

test.describe('landing and forum look', () => {
  test.setTimeout(600_000);

  test('forum home, topic list and topic have no overflow and pass contrast', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium-desktop', 'Runs once per database.');
    const topicPath = (await (await page.request.get('/forum')).text()).match(/href="(\/forum\/t\/\d+\/[^"]+)"/)?.[1];
    expect(topicPath, 'the demo forum has a topic').toBeTruthy();
    for (const viewport of viewports) {
      for (const scheme of schemes) {
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        for (const [name, path] of [['forum-home', '/forum'], ['forum-board', '/forum/general'], ['forum-topic', topicPath!]] as const) {
          await expectLookIsClean(page, `${name}-${viewport.name}-${scheme}`, path, scheme);
        }
      }
    }
  });

  test('landing has no overflow and passes contrast; the currency switch is hidden with one currency', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating site profile runs once per CI database.');
    test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');
    await loginAdmin(page);
    try {
      await setMode(page, 'landing');
      for (const viewport of viewports) {
        for (const scheme of schemes) {
          await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await expectLookIsClean(page, `landing-${viewport.name}-${scheme}`, '/', scheme);
        }
      }
      await page.goto('/', { waitUntil: 'domcontentloaded' });
      await expect(page.locator('.lp-hero h1')).toBeVisible();
      await expect(page.locator('[data-currency-switch]')).toHaveCount(0);
    } finally {
      await setMode(page, 'hybrid');
    }
  });
});
