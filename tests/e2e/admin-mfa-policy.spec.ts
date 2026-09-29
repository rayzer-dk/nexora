import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { expect, test } from '@playwright/test';

test.describe.configure({ retries: 0 });

// Runs only against a server started with ADMIN_REQUIRE_MFA=1 (see CI job / docs); its URL is passed in E2E_MFA_POLICY_URL.
const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
function totp(secret: string, step: number): string {
  let bits = '';
  for (const char of secret.replace(/[^A-Z2-7]/gi, '').toUpperCase()) bits += ALPHABET.indexOf(char).toString(2).padStart(5, '0');
  const bytes = Buffer.from(bits.match(/.{8}/g)!.map((byte) => parseInt(byte, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(step));
  const hash = createHmac('sha1', bytes).update(counter).digest();
  const offset = hash[19] & 0x0f;
  return String((((hash[offset] & 0x7f) << 24) | (hash[offset + 1] << 16) | (hash[offset + 2] << 8) | hash[offset + 3]) % 1_000_000).padStart(6, '0');
}

test('required 2FA policy forces enrolment and cannot be switched off from the panel', async ({ browser }, testInfo) => {
  const base = process.env.E2E_MFA_POLICY_URL;
  test.skip(!base, 'E2E_MFA_POLICY_URL (server with ADMIN_REQUIRE_MFA=1) is required.');
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating security audit runs once per CI database.');
  const context = await browser.newContext({ baseURL: base });
  const page = await context.newPage();
  try {
    await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
    await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
    await page.locator('button[type="submit"]').click();

    // Every admin page redirects to the enrolment screen, which shows the secret without ?setup=1.
    for (const target of ['/admin', '/admin/catalog/products', '/admin/system/extensions']) {
      await page.goto(target, { waitUntil: 'domcontentloaded' });
      await expect(page).toHaveURL(/\/admin\/account\/security/);
      await expect(page.locator('[data-mfa-secret] code')).toBeVisible();
    }
    const secret = (await page.locator('[data-mfa-secret] code').innerText()).replace(/\s+/g, '');
    await page.locator('form[action$="/security/confirm"] input[name="code"]').fill(totp(secret, Math.floor(Date.now() / 30000)));
    await page.locator('form[action$="/security/confirm"] button[type="submit"]').click();
    await expect(page.locator('[data-mfa-recovery] code')).toHaveCount(8);

    // Enrolled: the panel opens and there is no way to turn 2FA off.
    await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/catalog\/products/);
    await page.goto('/admin/account/security', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('form[action$="/security/disable"]')).toHaveCount(0);
  } finally {
    await context.close();
    execFileSync('php', ['bin/console', 'dbal:run-sql', 'DELETE FROM mc_admin_mfa', '--no-interaction'], { env: process.env });
  }
});
