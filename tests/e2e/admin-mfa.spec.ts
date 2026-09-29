import { createHmac } from 'node:crypto';
import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

test.describe.configure({ retries: 0 });

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function totp(secret: string, step: number): string {
  let bits = '';
  for (const char of secret.replace(/[^A-Z2-7]/gi, '').toUpperCase()) bits += ALPHABET.indexOf(char).toString(2).padStart(5, '0');
  const bytes = Buffer.from(bits.match(/.{8}/g)!.map((byte) => parseInt(byte, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(step));
  const hash = createHmac('sha1', bytes).update(counter).digest();
  const offset = hash[19] & 0x0f;
  const value = ((hash[offset] & 0x7f) << 24) | (hash[offset + 1] << 16) | (hash[offset + 2] << 8) | hash[offset + 3];
  return String(value % 1_000_000).padStart(6, '0');
}

async function signIn(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await page.locator('button[type="submit"]').click();
}

async function signOut(page: Page): Promise<void> {
  await page.context().clearCookies();
}

test('admin two-factor: enrolment, challenge gate, replay protection, recovery codes and disabling', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating security audit runs once per CI database.');
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Admin E2E credentials are required.');

  let recovery: string[] = [];
  try {
    await signIn(page);
    await page.waitForURL(/\/admin(?:\/|$)/);
    await page.goto('/admin/account/security?setup=1', { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    const secret = (await page.locator('[data-mfa-secret] code').innerText()).replace(/\s+/g, '');
    expect(secret).toMatch(/^[A-Z2-7]{32}$/);

    // A wrong code never enables 2FA.
    await page.locator('form[action$="/security/confirm"] input[name="code"]').fill('000000');
    await page.locator('form[action$="/security/confirm"] button[type="submit"]').click();
    await expect(page.locator('.admin-notice.is-error')).toHaveCount(1); // the flash is mirrored into a toast, so it may already be visually hidden
    await page.goto('/admin/account/security?setup=1', { waitUntil: 'domcontentloaded' });
    expect((await page.locator('[data-mfa-secret] code').innerText()).replace(/\s+/g, '')).toBe(secret);

    const step = Math.floor(Date.now() / 30000);
    await page.locator('form[action$="/security/confirm"] input[name="code"]').fill(totp(secret, step));
    await page.locator('form[action$="/security/confirm"] button[type="submit"]').click();
    await expect(page.locator('[data-mfa-recovery] code')).toHaveCount(8);
    recovery = await page.locator('[data-mfa-recovery] code').allInnerTexts();

    // New session: password alone must not open the admin.
    await signOut(page);
    await signIn(page);
    await page.waitForURL(/\/admin\/2fa$/);
    await page.goto('/admin/orders', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/2fa$/);

    // Wrong code is rejected.
    await page.locator('input[name="code"]').fill('000000');
    await page.locator('button[type="submit"]').first().click();
    await expect(page.locator('.admin-notice.is-error')).toHaveCount(1); // the flash is mirrored into a toast, so it may already be visually hidden

    // The code that enabled 2FA cannot be replayed.
    await page.locator('input[name="code"]').fill(totp(secret, step));
    await page.locator('button[type="submit"]').first().click();
    await expect(page.locator('.admin-notice.is-error')).toHaveCount(1); // the flash is mirrored into a toast, so it may already be visually hidden

    // The next time step is accepted and returns to the requested page.
    await page.locator('input[name="code"]').fill(totp(secret, step + 1));
    await page.locator('button[type="submit"]').first().click();
    await page.waitForURL(/\/admin(?:\/orders)?$/);
    await page.goto('/admin/orders', { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    await expect(page).toHaveURL(/\/admin\/orders$/);

    // A recovery code signs in once and only once.
    await signOut(page);
    await signIn(page);
    await page.waitForURL(/\/admin\/2fa$/);
    await page.locator('input[name="code"]').fill(recovery[0]);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForURL(/\/admin$/);
    await signOut(page);
    await signIn(page);
    await page.waitForURL(/\/admin\/2fa$/);
    await page.locator('input[name="code"]').fill(recovery[0]);
    await page.locator('button[type="submit"]').first().click();
    await expect(page.locator('.admin-notice.is-error')).toHaveCount(1); // the flash is mirrored into a toast, so it may already be visually hidden

    // Disable with another recovery code; sign-in goes straight to the dashboard afterwards.
    await page.locator('input[name="code"]').fill(recovery[1]);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForURL(/\/admin$/);
    await page.goto('/admin/account/security', { waitUntil: 'domcontentloaded' });
    await page.locator('form[action$="/security/disable"] input[name="code"]').fill(recovery[2]);
    await page.locator('form[action$="/security/disable"] button[type="submit"]').click();
    await page.locator('[data-admin-confirm] [data-confirm-accept]').click();
    await expect(page.locator('a[href*="setup=1"]')).toBeVisible();
    recovery = [];

    await signOut(page);
    await signIn(page);
    await page.waitForURL(/\/admin$/);
  } finally {
    if (recovery.length > 3) {
      // Cleanup if an assertion failed midway: leave the shared CI database without 2FA.
      await signOut(page);
      await signIn(page);
      await page.locator('input[name="code"]').fill(recovery[3]).catch(() => undefined);
      await page.locator('button[type="submit"]').first().click().catch(() => undefined);
      await page.goto('/admin/account/security', { waitUntil: 'domcontentloaded' }).catch(() => undefined);
      const form = page.locator('form[action$="/security/disable"]');
      if (await form.count()) {
        await form.locator('input[name="code"]').fill(recovery[4]);
        await form.locator('button[type="submit"]').click();
        await page.locator('[data-admin-confirm] [data-confirm-accept]').click().catch(() => undefined);
      }
    }
  }
});
