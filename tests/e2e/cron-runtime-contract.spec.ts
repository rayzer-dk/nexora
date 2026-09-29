import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([
    page.waitForURL(/\/admin(?:\/|$)/),
    page.locator('button[type="submit"]').click(),
  ]);
}

test('registered cron task runs and its real state is visible in admin', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Cron runtime contract runs once.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin credentials are required.');

  const output = execFileSync(
    'php',
    ['bin/console', 'commerce:cron:run', '--task=queue_purge', '--force', '--no-interaction'],
    { encoding: 'utf8', env: process.env },
  );
  expect(output).toContain('queue_purge: OK');

  await loginAdmin(page);
  await page.goto('/admin/system/cron', { waitUntil: 'domcontentloaded' });
  await expectNoServerError(page);

  const row = page.locator('tr[data-task-code="queue_purge"]');
  await expect(row).toBeVisible();
  await expect(row.locator('.admin-status-pill')).toContainText('success');
  await expect(row.locator('td').nth(2)).not.toContainText('ще не');
  await expect(row.locator('td').nth(4)).not.toContainText('перш');
});
