// Event photos: gallery, upload (through the browser-side rendition pipeline
// to object storage), chronological ordering from EXIF, dedup, exclude flag,
// permissions, admin reorder, delete.
//
// Needs photo storage configured for the app under test (real R2, or the
// local mock used during development). Set R2_CONFIGURED=0 to run only the
// storage-independent checks.
const { test, expect } = require('@playwright/test');
const path = require('path');
const { TestHelpers } = require('../utils/test-helpers');

const FIX = (f) => path.join(__dirname, '../fixtures', f);
const R2 = process.env.R2_CONFIGURED !== '0';
const USER_EMAIL = process.env.USER_EMAIL || 'pat@example.com';
const USER_PASSWORD = process.env.USER_PASSWORD || process.env.SUPER_PASSWORD || 'super';

test.describe.configure({ mode: 'serial' });

test.describe('Event photos', () => {
  let helpers;
  let eventId = null;

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    helpers = new TestHelpers(page);
    eventId = await helpers.createTestEventViaPhp();
    await page.close();
  });

  test.afterAll(async ({ browser }) => {
    if (!eventId) return;
    const page = await browser.newPage();
    const h = new TestHelpers(page);
    await h.loginAsAdmin();
    // Delete remaining photos through the UI so storage objects go too.
    await page.goto(`/event_photos.php?event_id=${eventId}`);
    page.on('dialog', (d) => d.accept());
    while (await page.locator('.photo-tile .tile-btn.delete').count() > 0) {
      const before = await page.locator('.photo-tile').count();
      await page.locator('.photo-tile .tile-btn.delete').first().click({ force: true });
      await expect(page.locator('.photo-tile')).toHaveCount(before - 1);
    }
    await h.deleteTestEvent(eventId);
    await page.close();
  });

  test.beforeEach(async ({ page }) => {
    helpers = new TestHelpers(page);
  });

  test('gallery page renders with the upload card for a logged-in user', async ({ page }) => {
    await helpers.loginAsAdmin();
    await page.goto(`/event_photos.php?event_id=${eventId}`);
    await expect(page.locator('h2')).toContainText('Photos:');
    await expect(page.locator('#photoGrid')).toBeAttached();
    if (R2) {
      await expect(page.locator('#photoUploader')).toBeVisible();
    } else {
      await expect(page.locator('text=Photo storage is not configured')).toBeVisible();
    }
    // Photos appear in the top nav and the event page links here.
    await expect(page.locator('header nav a[href="/photos.php"]')).toBeVisible();
    await page.goto(`/event.php?id=${eventId}`);
    await expect(page.locator('#eventPhotosCard a[href^="/event_photos.php?event_id="]').first()).toBeVisible();
  });

  test('photos index renders', async ({ page }) => {
    await helpers.loginAsAdmin();
    await page.goto('/photos.php');
    await expect(page.locator('h2')).toHaveText('Photos');
    await expect(page.locator('a[href="/slideshows.php"]')).toBeVisible();
  });

  test('uploads photos, orders them by EXIF date, and dedups', async ({ page }) => {
    test.skip(!R2, 'photo storage not configured');
    await helpers.loginAsAdmin();
    await page.goto(`/event_photos.php?event_id=${eventId}`);
    // Later-dated file first on purpose: the gallery must still order by capture time.
    await page.setInputFiles('#photoUploader input[type="file"]', [FIX('campout-2.jpg'), FIX('campout-1.jpg'), FIX('no-exif.png')]);
    await expect(page.locator('.photo-tile')).toHaveCount(3, { timeout: 30000 });
    await expect(page.locator('#photoCount')).toHaveText('3');

    const taken = await page.locator('.photo-tile').evaluateAll((els) => els.map((e) => e.getAttribute('data-taken-text')));
    expect(taken[0]).toContain('Sep 12, 2026 10:05 AM');
    expect(taken[1]).toContain('Sep 12, 2026 2:30 PM');
    expect(taken[2]).toContain('(estimated)');     // PNG: no EXIF, date from the file

    // Portrait original keeps its orientation in the stored dimensions.
    const dims = await page.locator('.photo-tile img').first().evaluate((img) => [img.getAttribute('width'), img.getAttribute('height')]);
    expect(Number(dims[0])).toBeLessThan(Number(dims[1]));

    // Re-selecting the same file is detected before any upload happens.
    await page.setInputFiles('#photoUploader input[type="file"]', [FIX('campout-1.jpg')]);
    await expect(page.locator('#uploadQueue .upload-row .status').last()).toContainText('Already in this event', { timeout: 15000 });
    await expect(page.locator('.photo-tile')).toHaveCount(3);
  });

  test('lightbox, caption and exclude-from-slideshow toggle', async ({ page }) => {
    test.skip(!R2, 'photo storage not configured');
    await helpers.loginAsAdmin();
    await page.goto(`/event_photos.php?event_id=${eventId}`);
    const first = page.locator('.photo-tile').first();
    await first.locator('.photo-open').click();
    await expect(page.locator('#photoLightbox')).toBeVisible();
    await expect(page.locator('#lightboxImg')).toHaveAttribute('src', /photos\//);
    await page.fill('#lightboxCaptionInput', 'Flag ceremony');
    await page.locator('#lightboxCaptionForm button[type="submit"]').click();
    await expect(page.locator('.photo-tile').first()).toHaveAttribute('data-caption', 'Flag ceremony');
    await page.locator('#lightboxClose').click();

    await page.locator('.photo-tile').nth(1).locator('.toggle-slideshow').click({ force: true });
    await expect(page.locator('.photo-tile').nth(1)).toHaveClass(/excluded/);
    await expect(page.locator('.photo-tile').nth(1).locator('.tile-badge')).toHaveText('Not in slideshow');
    await page.locator('.photo-tile').nth(1).locator('.toggle-slideshow').click({ force: true });
    await expect(page.locator('.photo-tile').nth(1)).not.toHaveClass(/excluded/);
  });

  test('a regular user sees photos but cannot modify or reorder them', async ({ page }) => {
    test.skip(!R2, 'photo storage not configured');
    await helpers.login(USER_EMAIL, USER_PASSWORD);
    await page.goto(`/event_photos.php?event_id=${eventId}`);
    await expect(page.locator('.photo-tile')).toHaveCount(3);
    await expect(page.locator('.photo-tile .tile-controls')).toHaveCount(0);
    await expect(page.locator('#reorderBtn')).toHaveCount(0);
    // Direct API call is refused too.
    const csrf = await page.locator('#photoGrid').getAttribute('data-csrf');
    const photoId = await page.locator('.photo-tile').first().getAttribute('data-photo-id');
    const res = await page.request.post('/event_photo_delete.php', { form: { csrf, photo_id: photoId } });
    expect(res.status()).toBe(403);
  });

  test('admin can reorder photos and reset to chronological', async ({ page }) => {
    test.skip(!R2, 'photo storage not configured');
    await helpers.loginAsAdmin();
    await page.goto(`/event_photos.php?event_id=${eventId}`);
    const ids = await page.locator('.photo-tile').evaluateAll((els) => els.map((e) => e.getAttribute('data-photo-id')));
    await page.locator('#reorderBtn').click();
    await expect(page.locator('#photoGrid')).toHaveClass(/reordering/);
    await page.locator('.photo-tile').first().locator('.move-right').click();
    await page.locator('#saveOrderBtn').click();
    // The page reloads after saving; the Reset button only exists once a manual order is stored.
    await expect(page.locator('#resetOrderBtn')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('#photoGrid')).not.toHaveClass(/reordering/);
    const after = await page.locator('.photo-tile').evaluateAll((els) => els.map((e) => e.getAttribute('data-photo-id')));
    expect(after).toEqual([ids[1], ids[0], ids[2]]);
    page.once('dialog', (d) => d.accept());
    await page.locator('#resetOrderBtn').click();
    // Reload again; the Reset button disappears once the order is chronological.
    await expect(page.locator('#resetOrderBtn')).toHaveCount(0, { timeout: 10000 });
    const reset = await page.locator('.photo-tile').evaluateAll((els) => els.map((e) => e.getAttribute('data-photo-id')));
    expect(reset).toEqual(ids);
  });

  test('deleting a photo removes it and updates the count', async ({ page }) => {
    test.skip(!R2, 'photo storage not configured');
    await helpers.loginAsAdmin();
    await page.goto(`/event_photos.php?event_id=${eventId}`);
    page.once('dialog', (d) => d.accept());
    await page.locator('.photo-tile').last().locator('.tile-btn.delete').click({ force: true });
    await expect(page.locator('.photo-tile')).toHaveCount(2);
    await expect(page.locator('#photoCount')).toHaveText('2');
  });
});
