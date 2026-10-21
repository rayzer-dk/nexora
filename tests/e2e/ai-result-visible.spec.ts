import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError, loginAdmin } from './helpers';

test.describe.configure({ retries: 0, mode: 'serial' });

async function setAi(page: Page, enabled: boolean): Promise<void> {
  await page.goto('/admin/system/ai', { waitUntil: 'domcontentloaded' });
  const form = page.locator('form:has(input[name="api_key"])').first();
  await form.locator('input[name="enabled"]').setChecked(enabled);
  if (enabled) await form.locator('input[name="api_key"]').fill('sk-test-0123456789abcdef');
  await Promise.all([page.waitForURL(/system\/ai/), form.locator('button[type="submit"]').click()]);
}

const LONG_TEXT = `<p>${'Довгий опис, який ШІ написав для картки товару. '.repeat(25)}</p>`;

test('AI texts are shown, say where they went, and the field button opens its tab', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings run once.');
  await loginAdmin(page);
  // The provider needs the internet: the browser gets canned answers, so the click flow is what is tested.
  let answer: Record<string, string> = { meta_title: 'SEO title from AI', meta_description: 'SEO description from AI' };
  await page.route('**/admin/api/ai/task', (route) => route.fulfill({ json: { ok: true, fields: answer } }));
  await page.route('**/admin/api/ai/product-draft', (route) => route.fulfill({ json: { ok: true, short_description: 'Short AI text', description: LONG_TEXT } }));
  await page.route('**/admin/api/ai/product-from-photo', (route) => route.fulfill({ json: { ok: true, fields: { name: 'Photo AI name', brand: 'Acme', short_description: 'Photo short', description: '<p>Photo long</p>', attributes: 'Colour: red' } } }));
  try {
    await setAi(page, true);
    await page.goto('/admin/catalog/products', { waitUntil: 'domcontentloaded' });
    const href = await page.locator('a[href*="/admin/catalog/products/"][href$="/edit"]').first().getAttribute('href');
    await page.goto(href!, { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    const form = page.locator('form#product-form');

    // SEO box: the generated text is in the box, "inserted into" names the field, the field really holds the text
    await page.locator('.admin-tab[data-tab-target="seo"]').click();
    const seoBox = page.locator('[data-product-tab="seo"] [data-ai-box]');
    await seoBox.locator('[data-ai-task="seo_meta"]').click();
    const seoResult = seoBox.locator('[data-ai-result]');
    await expect(seoResult).toContainText('SEO title from AI');
    await expect(seoResult).toContainText('SEO description from AI');
    await expect(seoResult.locator('[data-ai-result-where]').first()).toContainText('Вставлено в:');
    await expect(form.locator('[name="meta_title"]')).toHaveValue('SEO title from AI');
    await expect(form.locator('[name="meta_description"]')).toHaveValue('SEO description from AI');
    await expect(seoBox.locator('[data-ai-status]')).not.toHaveClass(/is-error/);

    // "Show field" flashes the field
    await seoResult.locator('[data-ai-reveal-field]').first().click();
    await expect(form.locator('[name="meta_title"]')).toHaveClass(/is-ai-flash/);
    await expect(form.locator('[name="meta_title"]')).not.toHaveClass(/is-ai-flash/, { timeout: 5_000 });

    // a field that is not on the page: honest error, the text stays available with a copy button
    await form.locator('[name="meta_title"], [name="meta_description"]').evaluateAll((fields) => fields.forEach((field) => field.removeAttribute('name')));
    await seoBox.locator('[data-ai-task="seo_meta"]').click();
    await expect(seoBox.locator('[data-ai-status]')).toContainText('Не вдалося вставити: поле не знайдено');
    await expect(seoBox.locator('[data-ai-status]')).toHaveClass(/is-error/);
    await expect(seoBox.locator('[data-ai-result]')).toContainText('SEO title from AI');
    await expect(seoBox.locator('[data-ai-copy]').first()).toBeVisible();
    await expect(seoBox.locator('[data-ai-reveal-field]')).toHaveCount(0);

    // an empty field from the model is named, and the other field is still written
    await page.reload({ waitUntil: 'domcontentloaded' });
    answer = { meta_title: '', meta_description: 'Only the description' };
    await page.locator('.admin-tab[data-tab-target="seo"]').click();
    await seoBox.locator('[data-ai-task="seo_meta"]').click();
    await expect(seoBox.locator('[data-ai-status]')).toContainText('порожній текст');
    await expect(form.locator('[name="meta_description"]')).toHaveValue('Only the description');

    // product draft: the long text is cut to a preview, "show in full" expands it; the visual editor shows it
    await page.locator('.admin-tab[data-tab-target="general"]').click();
    const draftBox = page.locator('[data-product-tab="general"] .admin-ai-helper:has([data-ai-product-draft])');
    await draftBox.locator('[data-ai-product-draft]').click();
    const draftResult = draftBox.locator('[data-ai-result]');
    await expect(draftResult).toContainText('Short AI text');
    const longItem = draftResult.locator('[data-ai-result-item]').filter({ hasNot: page.locator('[data-ai-copy]') }).last();
    const preview = longItem.locator('[data-ai-result-text]');
    expect((await preview.innerText()).length).toBeLessThanOrEqual(301);
    await longItem.locator('[data-ai-result-more]').click();
    expect((await preview.innerText()).length).toBeGreaterThan(400);
    await expect(form.locator('.rich-editor-mount').first()).toContainText('Довгий опис');

    // the field button opens the tab of the field even when another tab is open, and flashes the visual editor
    await page.locator('.admin-tab[data-tab-target="sales"]').click();
    const generalPanel = page.locator('[data-product-tab="general"]').first();
    await expect(generalPanel).toBeHidden();
    await draftResult.locator('[data-ai-reveal-field]').last().evaluate((button: HTMLElement) => button.click());
    await expect(generalPanel).toBeVisible();
    await expect(form.locator('.rich-editor-mount').first()).toHaveClass(/is-ai-flash/);

    // photo card writes only into the fields of its own form, never the first [name=name] of the page
    await page.evaluate(() => {
      const decoy = document.createElement('input');
      decoy.name = 'name';
      decoy.id = 'decoy-name';
      document.body.prepend(decoy);
    });
    const photoBox = page.locator('[data-ai-photo]');
    await photoBox.locator('[data-ai-photo-file]').setInputFiles({ name: 'p.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'base64') });
    await photoBox.locator('[data-ai-photo-run]').click();
    await expect(form.locator('[name="name"]')).toHaveValue('Photo AI name');
    await expect(page.locator('#decoy-name')).toHaveValue('');
    await expect(photoBox.locator('[data-ai-result]')).toContainText('Photo AI name');
    await expect(photoBox.locator('[data-ai-result]')).toContainText('Acme');
    await expect(photoBox.locator('[data-ai-result-where]').first()).toContainText('Вставлено в:');
    await expect(photoBox.locator('[data-ai-photo-status]')).not.toHaveClass(/is-error/);
  } finally {
    await setAi(page, false);
  }
});

test('category text, replies and the playground report honestly', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating settings run once.');
  await loginAdmin(page);
  let answer: Record<string, string> = { top: '<p>Top text</p>', bottom: '<p>Bottom text</p>' };
  await page.route('**/admin/api/ai/task', (route) => route.fulfill({ json: { ok: true, fields: answer } }));
  try {
    await setAi(page, true);
    await page.goto('/admin/catalog/categories/new', { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    const box = page.locator('[data-ai-box]').first();
    await page.locator('input[name="name"]').first().fill('AI category');
    await box.locator('[data-ai-task="category_text"]').click();
    await expect(box.locator('[data-ai-result]')).toContainText('Top text');
    await expect(page.locator('textarea[name="description"]')).toHaveValue('<p>Top text</p>\n<p>Bottom text</p>');
    await expect(box.locator('[data-ai-result-where]').first()).toContainText('Вставлено в:');

    // nothing usable came back: no "inserted", the empty fields are named
    answer = { top: '', bottom: '' };
    await box.locator('[data-ai-task="category_text"]').click();
    await expect(box.locator('[data-ai-status]')).toHaveClass(/is-error/);
    await expect(box.locator('[data-ai-status]')).toContainText('порожній текст');
    await expect(box.locator('[data-ai-status]')).not.toContainText('Чернетку вставлено');

    // replies to reviews and questions (only when the shop has some): the selector must resolve and the reply must land in its own box
    answer = { reply: 'Thank you for the review' };
    await page.goto('/admin/customer-experience', { waitUntil: 'domcontentloaded' });
    await expectNoServerError(page);
    const reply = page.locator('[data-ai-task="reply"]').first();
    if (await reply.count()) {
      expect(await reply.getAttribute('data-ai-in')).not.toContain('{{');
      const holder = reply.locator('xpath=ancestor::form[1]');
      await reply.evaluate((button: HTMLElement) => button.click());
      await expect(holder.locator('textarea').first()).toHaveValue('Thank you for the review');
      await expect(holder.locator('[data-ai-result]')).toContainText('Thank you for the review');
    }

    // playground: an empty answer is not reported as success
    answer = { reply: '' };
    await page.goto('/admin/system/ai', { waitUntil: 'domcontentloaded' });
    const playground = page.locator('[data-ai-playground]');
    await playground.locator('[data-ai-play-input]').fill('Hello');
    await playground.locator('[data-ai-play-run]').click();
    await expect(playground.locator('[data-ai-status]')).toHaveClass(/is-error/);
    await expect(playground.locator('[data-ai-play-output]')).toHaveValue('');
  } finally {
    await setAi(page, false);
  }
});
