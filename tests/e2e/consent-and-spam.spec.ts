import { expect, test } from '@playwright/test';

test.describe('cookie consent', () => {
  test.beforeEach(({}, testInfo) => { test.skip(testInfo.project.name !== 'chromium-desktop'); });

  test('nothing optional is stored before a choice; every choice is honoured', async ({ page, context }) => {
    await page.goto('/', { waitUntil: 'networkidle' });
    const banner = page.locator('[data-commerce-consent]');
    await expect(banner).toBeVisible();
    await expect(banner.locator('a[href="/cookie-policy"]')).toHaveCount(1);
    await expect(banner.locator('a[href="/privacy-policy"]')).toHaveCount(1);
    expect(await page.evaluate(() => localStorage.getItem('mc_consent_v1'))).toBeNull();
    expect(await page.locator('script[data-consent-activated]').count()).toBe(0);

    // first visit: Escape must not dismiss without a choice
    await page.keyboard.press('Escape');
    await expect(banner).toBeVisible();

    // product page before consent: no recently-viewed storage
    await page.goto('/iphone-x', { waitUntil: 'networkidle' });
    await expect(page.locator('[data-recent-track]')).toHaveCount(1);
    expect(await page.evaluate(() => localStorage.getItem('mc_recent'))).toBeNull();

    // reject
    await page.locator('[data-consent-reject-optional]').click();
    await expect(banner).toBeHidden();
    let saved = JSON.parse(await page.evaluate(() => localStorage.getItem('mc_consent_v1') || 'null'));
    expect([saved.preferences, saved.analytics, saved.marketing]).toEqual([false, false, false]);
    await page.reload({ waitUntil: 'networkidle' });
    await expect(banner).toBeHidden();
    expect(await page.evaluate(() => localStorage.getItem('mc_recent'))).toBeNull();

    // granular: preferences only; Escape closes the reopened panel
    await page.locator('[data-consent-open]').first().click();
    await expect(banner).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(banner).toBeHidden();
    await page.locator('[data-consent-open]').first().click();
    await page.locator('[data-commerce-consent] input[name="consent_preferences"]').check();
    await page.locator('[data-consent-save]').click();
    saved = JSON.parse(await page.evaluate(() => localStorage.getItem('mc_consent_v1') || 'null'));
    expect([saved.preferences, saved.analytics, saved.marketing]).toEqual([true, false, false]);
    await page.reload({ waitUntil: 'networkidle' });
    await expect.poll(() => page.evaluate(() => localStorage.getItem('mc_recent'))).not.toBeNull();

    // revoking removes the stored personalisation data
    await page.locator('[data-consent-open]').first().click();
    await page.locator('[data-consent-reject-optional]').click();
    await page.reload({ waitUntil: 'networkidle' });
    expect(await page.evaluate(() => localStorage.getItem('mc_recent'))).toBeNull();
    expect((await context.cookies()).some((c) => /^_ga|_fbp/.test(c.name))).toBe(false);
  });
});

test.describe('spam protection', () => {
  test('honeypot and missing timestamp reject anonymous forms', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium-desktop');
    await page.goto('/', { waitUntil: 'networkidle' });
    const form = page.locator('form.newsletter-signup__form');
    await expect(form.locator('input[name="_website"]')).toHaveCount(1);
    await expect(form.locator('input[name="_rendered_at"]')).toHaveCount(1);
    const email = `bot-${Date.now()}@example.test`;

    // honeypot filled -> rejected with an error notice
    await page.evaluate(() => { (document.querySelector('.newsletter-signup__form input[name="_website"]') as HTMLInputElement).value = 'http://spam.example'; });
    await form.locator('input[name="email"]').fill(email);
    await form.locator('button[type="submit"]').click();
    await expect(page.locator('.storefront-toast.is-error')).toHaveCount(1);

    // missing render timestamp -> rejected
    await page.goto('/', { waitUntil: 'networkidle' });
    await page.evaluate(() => { document.querySelector('.newsletter-signup__form input[name="_rendered_at"]')?.remove(); });
    await form.locator('input[name="email"]').fill(email);
    await form.locator('button[type="submit"]').click();
    await expect(page.locator('.storefront-toast.is-error')).toHaveCount(1);

    // a normal submission passes the gate (success or an already-subscribed notice, never the spam error)
    await page.goto('/', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    await form.locator('input[name="email"]').fill(email);
    await form.locator('button[type="submit"]').click();
    await expect(page.locator('.storefront-toast:not(.is-error)')).toHaveCount(1);
  });
});
