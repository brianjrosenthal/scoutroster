// Slideshows: admin creates one from events, uploads music, publishes; members
// see only published ones; the player loads its manifest and starts.
const { test, expect } = require('@playwright/test');
const path = require('path');
const { TestHelpers } = require('../utils/test-helpers');

const FIX = (f) => path.join(__dirname, '../fixtures', f);
const R2 = process.env.R2_CONFIGURED !== '0';
const USER_EMAIL = process.env.USER_EMAIL || 'pat@example.com';
const USER_PASSWORD = process.env.USER_PASSWORD || process.env.SUPER_PASSWORD || 'super';
const TITLE = 'Playwright Year in Review ' + Date.now();

test.describe.configure({ mode: 'serial' });

test.describe('Slideshows', () => {
  let helpers;
  let eventId = null;
  let slideshowId = null;

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    helpers = new TestHelpers(page);
    eventId = await helpers.createTestEventViaPhp({ past: true });
    await page.close();
  });

  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    const h = new TestHelpers(page);
    await h.loginAsAdmin();
    page.on('dialog', (d) => d.accept());
    if (slideshowId) {
      await page.goto(`/admin_slideshow_edit.php?id=${slideshowId}`);
      await page.locator('button.danger:has-text("Delete slideshow")').click();
      await page.waitForLoadState('networkidle');
    }
    if (eventId) {
      await page.goto(`/event_photos.php?event_id=${eventId}`);
      while (await page.locator('.photo-tile .tile-btn.delete').count() > 0) {
        const before = await page.locator('.photo-tile').count();
        await page.locator('.photo-tile .tile-btn.delete').first().click({ force: true });
        await expect(page.locator('.photo-tile')).toHaveCount(before - 1);
      }
      await h.deleteTestEvent(eventId);
    }
    await page.close();
  });

  test.beforeEach(async ({ page }) => {
    helpers = new TestHelpers(page);
  });

  test('admin creates a slideshow and adds an event that has photos', async ({ page }) => {
    await helpers.loginAsAdmin();
    await page.goto('/slideshows.php');
    await page.fill('form input[name="title"]', TITLE);
    await page.locator('form:has(input[value="create"]) button[type="submit"]').click();
    await expect(page).toHaveURL(/admin_slideshow_edit\.php\?id=\d+/);
    slideshowId = Number(new URL(page.url()).searchParams.get('id'));

    // An event without photos is not offered.
    await expect(page.locator(`select[name="event_id"] option[value="${eventId}"]`)).toHaveCount(0);
    if (!R2) return;

    await page.goto(`/event_photos.php?event_id=${eventId}`);
    await page.setInputFiles('#photoUploader input[type="file"]', [FIX('campout-1.jpg'), FIX('campout-2.jpg')]);
    await expect(page.locator('.photo-tile')).toHaveCount(2, { timeout: 30000 });

    await page.goto(`/admin_slideshow_edit.php?id=${slideshowId}`);
    await page.selectOption('select[name="event_id"]', { value: String(eventId) });
    await page.locator('form:has(input[value="add_section"]) button[type="submit"]').click();
    await expect(page.locator('#sectionsTable tr[data-section-id]')).toHaveCount(1);
    await expect(page.locator('#sectionsTable')).toContainText('2');
    await expect(page.locator('#sectionsTable')).toContainText('no music');
  });

  test('a draft is hidden from regular members', async ({ page }) => {
    await helpers.login(USER_EMAIL, USER_PASSWORD);
    await page.goto('/slideshows.php');
    await expect(page.locator(`text=${TITLE}`)).toHaveCount(0);
    const res = await page.request.get(`/slideshow_manifest.php?id=${slideshowId}`);
    expect(res.status()).toBe(403);
    const play = await page.request.get(`/slideshow_play.php?id=${slideshowId}`);
    expect(play.status()).toBe(403);
  });

  test('photos, music, publish, and the player starts', async ({ page }) => {
    test.skip(!R2, 'photo storage not configured');
    await helpers.loginAsAdmin();
    await page.goto(`/admin_slideshow_edit.php?id=${slideshowId}`);
    await expect(page.locator('#sectionsTable')).toContainText('no music');

    // Upload music for the section; the page reloads with the track assigned.
    await page.setInputFiles('.track-upload input[type="file"]', FIX('track.wav'));
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#sectionsTable select[name="track_id"] option:checked')).toContainText('track', { timeout: 20000 });
    await expect(page.locator('#sectionsTable')).not.toContainText('no music');

    // Publish, with random transitions.
    await page.selectOption('select[name="transition"]', 'random');
    await page.check('input[name="is_published"]');
    await page.locator('form:has(input[value="save_meta"]) button[type="submit"]').click();
    await expect(page.locator('.flash')).toContainText('Saved');

    // Manifest has the section with two photos.
    const res = await page.request.get(`/slideshow_manifest.php?id=${slideshowId}`);
    expect(res.status()).toBe(200);
    const m = await res.json();
    expect(m.ok).toBe(true);
    expect(m.sections).toHaveLength(1);
    expect(m.sections[0].photos).toHaveLength(2);
    expect(m.sections[0].track).not.toBeNull();
    expect(m.sections[0].cues).toHaveLength(1);
    expect(m.sections[0].cues[0].track.id).toBe(m.sections[0].track.id);
    expect(m.sections[0].photo_starts).toHaveLength(2);
    expect(m.sections[0].seconds_per_photo).toBeGreaterThanOrEqual(1);
    expect(m.transition).toBe('random');

    // Player: begin, title card, then a photo appears.
    await page.goto(`/slideshow_play.php?id=${slideshowId}`);
    await expect(page.locator('#begin')).toBeEnabled({ timeout: 10000 });
    await page.locator('#begin').click();
    await expect(page.locator('#card h1')).not.toBeEmpty();
    await expect(page.locator('.layer.active img')).toHaveAttribute('src', /photos\//, { timeout: 15000 });
  });

  test('a published slideshow is visible to regular members', async ({ page }) => {
    test.skip(!R2, 'photo storage not configured');
    await helpers.login(USER_EMAIL, USER_PASSWORD);
    await page.goto('/slideshows.php');
    await expect(page.locator(`text=${TITLE}`).first()).toBeVisible();
    const res = await page.request.get(`/slideshow_manifest.php?id=${slideshowId}`);
    expect(res.status()).toBe(200);
  });
});
