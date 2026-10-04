import { expect, test, type Page } from '@playwright/test';
import { expectNoServerError } from './helpers';

// Release 3.44.0: banners, scroll story and video hero in the home builder; device visibility works on the home page.

test.describe.configure({ retries: 0, mode: 'serial' });

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="_username"]').fill(process.env.E2E_ADMIN_EMAIL!);
  await page.locator('input[name="_password"]').fill(process.env.E2E_ADMIN_PASSWORD!);
  await Promise.all([page.waitForURL(/\/admin(?:\/(?!login)|$)/), page.locator('button[type="submit"]').click()]);
}

async function publish(page: Page, layoutJson: string): Promise<void> {
  await page.goto('/admin/appearance/builder/home', { waitUntil: 'domcontentloaded' });
  const token = await page.locator('#builder-form input[name="_csrf_token"]').inputValue();
  const response = await page.request.post('/admin/appearance/builder/home', {
    maxRedirects: 0,
    form: { _csrf_token: token, layout_json: layoutJson, builder_action: 'publish' },
  });
  expect(response.status()).toBeGreaterThanOrEqual(300);
  expect(response.status()).toBeLessThan(400);
}

const everywhere = { desktop: true, tablet: true, mobile: true };
const demoImage = '/media/demo/laptop-pro-14.webp';

test('banners form a row, hide per device and keep their own colours; story and video blocks render', async ({ page, browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating the layout runs once.');
  await loginAdmin(page);
  await page.goto('/admin/appearance/builder/home', { waitUntil: 'domcontentloaded' });
  const originalRaw = await page.locator('textarea[data-layout-json]').inputValue();
  const layout = JSON.parse(originalRaw);
  layout.blocks.push(
    { id: 'banner_a', component: 'banner', enabled: true, props: { image: demoImage, title: 'Banner A', width: 'half', height: 'm', position: 'ml', url: '/catalog' }, style: { color: '#ffffff' }, visibility: everywhere },
    { id: 'banner_b', component: 'banner', enabled: true, props: { title: 'Banner B', width: 'half', height: 'm', position: 'bc' }, style: { color: '#111111', background: 'linear-gradient(90deg,#ff0000,#0000ff)' }, visibility: everywhere },
    { id: 'banner_c', component: 'banner', enabled: true, props: { title: 'Only on computers', width: 'full', height: 's' }, style: { background: '#123456' }, visibility: { desktop: true, tablet: true, mobile: false } },
    { id: 'scroll_story_a', component: 'scroll_story', enabled: true, props: { title: 'Story', step1_title: 'First step', step1_image: demoImage, step2_title: 'Second step', step2_image: '/media/demo/smartphone-neo-x1.webp', step3_title: 'Third step', step3_image: '/media/demo/headphones-airbeat.webp' }, style: {}, visibility: everywhere },
    { id: 'video_hero_a', component: 'video_hero', enabled: true, props: { title: 'Video title', image: demoImage, video_url: 'https://example.com/none.mp4', height: 'm' }, style: { color: '#ffffff' }, visibility: everywhere },
  );
  await publish(page, JSON.stringify(layout));

  try {
    await page.setViewportSize({ width: 1440, height: 900 });
    const response = await page.goto('/', { waitUntil: 'domcontentloaded' });
    expect(response?.status()).toBe(200);
    await expectNoServerError(page);

    // two half banners share one row; the third (full width) is below them
    const a = await page.locator('[data-banner="banner_a"]').boundingBox();
    const b = await page.locator('[data-banner="banner_b"]').boundingBox();
    const c = await page.locator('[data-banner="banner_c"]').boundingBox();
    expect(a && b && c).toBeTruthy();
    expect(Math.abs(a!.y - b!.y)).toBeLessThan(2);
    expect(b!.x).toBeGreaterThan(a!.x + a!.width - 2);
    expect(c!.y).toBeGreaterThan(a!.y + a!.height - 2);
    // own text colour and gradient background of a banner
    expect(await page.locator('[data-banner="banner_b"] .banner__title').evaluate((el) => getComputedStyle(el).color)).toBe('rgb(17, 17, 17)');
    expect(await page.locator('[data-banner="banner_b"]').evaluate((el) => getComputedStyle(el).backgroundImage)).toContain('linear-gradient');
    // a malicious or invalid link / colour is dropped, not printed
    await expect(page.locator('[data-banner="banner_a"]')).toHaveAttribute('href', '/catalog');

    // scroll story: the picture and step follow the step in view (computers)
    const story = page.locator('[data-home-builder-block="scroll_story_a"]');
    await story.scrollIntoViewIfNeeded();
    await expect(story.locator('[data-story-step="0"]')).toHaveClass(/is-active/);
    await story.locator('[data-story-step="2"]').scrollIntoViewIfNeeded();
    await expect(story.locator('[data-story-step="2"]')).toHaveClass(/is-active/);
    await expect(story.locator('[data-story-image="2"]')).toHaveClass(/is-active/);
    await expect(story.locator('[data-story-image="0"]')).not.toHaveClass(/is-active/);

    // video hero: the poster is there; with reduced motion the video never gets a source
    const reduced = await browser.newContext({ reducedMotion: 'reduce', viewport: { width: 1440, height: 900 } });
    const quiet = await reduced.newPage();
    await quiet.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(quiet.locator('[data-home-builder-block="video_hero_a"] .video-hero__poster')).toHaveCount(1);
    await quiet.locator('[data-home-builder-block="video_hero_a"]').scrollIntoViewIfNeeded();
    await quiet.waitForTimeout(500);
    expect(await quiet.locator('[data-home-builder-block="video_hero_a"] video').evaluate((v: HTMLVideoElement) => v.getAttribute('src'))).toBeNull();
    await reduced.close();

    // phone: the computer-only banner is gone, banners stack
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.locator('[data-banner="banner_c"]')).toBeHidden();
    const am = await page.locator('[data-banner="banner_a"]').boundingBox();
    const bm = await page.locator('[data-banner="banner_b"]').boundingBox();
    expect(bm!.y).toBeGreaterThan(am!.y + am!.height - 2);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
  } finally {
    await publish(page, originalRaw);
  }
});

test('the builder offers the new blocks with their own settings', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Builder runs on the desktop project.');
  await loginAdmin(page);
  await page.goto('/admin/appearance/builder/home', { waitUntil: 'domcontentloaded' });
  const add = page.locator('[data-add-component]');
  for (const component of ['banner', 'scroll_story', 'video_hero']) {
    await expect(add.locator(`option[value="${component}"]`)).toHaveCount(1);
  }
  await add.selectOption('banner');
  await page.locator('[data-add-block]').click();
  const inspector = page.locator('[data-inspector]');
  await expect(inspector.locator('.mc-pos-grid')).toBeVisible();
  await expect(inspector.locator('select[data-prop="props.width"]')).toBeVisible();
  await expect(inspector.locator('[data-open-media="image_mobile"]')).toBeVisible();
  await inspector.locator('input[data-prop="props.title"]').fill('Builder banner');
  await inspector.locator('.mc-pos-grid__cell input[value="br"]').check({ force: true });
  const json = JSON.parse(await page.locator('textarea[data-layout-json]').inputValue());
  const block = json.blocks.find((b: { component: string }) => b.component === 'banner');
  expect(block.props.title).toBe('Builder banner');
  expect(block.props.position).toBe('br');
  // nothing is saved: leaving without publishing keeps the live layout
});
