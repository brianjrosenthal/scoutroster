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

  test('photos without EXIF are marked, and setting a date re-sorts them', async ({ page }) => {
    test.skip(!R2, 'photo storage not configured');
    await helpers.loginAsAdmin();
    await page.goto(`/event_photos.php?event_id=${eventId}`);
    // The PNG (no EXIF) is last and carries the "date?" marker; the EXIF photos do not.
    const last = page.locator('.photo-tile').last();
    await expect(last).toHaveAttribute('data-estimated', '1');
    await expect(last.locator('.tile-date-est')).toBeVisible();
    await expect(page.locator('.photo-tile').first().locator('.tile-date-est')).toHaveCount(0);
    const pngId = await last.getAttribute('data-photo-id');

    // Give it a date before the others: it moves to the front and loses the marker.
    await last.locator('.photo-open').click();
    await expect(page.locator('#lightboxDateForm')).toBeVisible();
    await page.fill('#lightboxDateInput', '2026-09-12T08:00');
    await page.locator('#lightboxDateForm button[type="submit"]').click();
    await expect(page.locator('.photo-tile').first()).toHaveAttribute('data-photo-id', pngId);
    await expect(page.locator('.photo-tile').first()).toHaveAttribute('data-estimated', '0');
    await expect(page.locator('#lightboxMeta')).toContainText('Sep 12, 2026 8:00 AM');
    await expect(page.locator('#lightboxMeta')).not.toContainText('estimated');
    // Clearing the date makes it estimated again (upload time), so it goes back to the end.
    await page.fill('#lightboxDateInput', '');
    await page.locator('#lightboxDateForm button[type="submit"]').click();
    await expect(page.locator('.photo-tile').last()).toHaveAttribute('data-photo-id', pngId);
    await expect(page.locator('.photo-tile').last()).toHaveAttribute('data-estimated', '1');
    await page.locator('#lightboxClose').click();
  });

  test('a regular user sees photos but cannot modify or reorder them', async ({ page }) => {
    test.skip(!R2, 'photo storage not configured');
    await helpers.login(USER_EMAIL, USER_PASSWORD);
    await page.goto(`/event_photos.php?event_id=${eventId}`);
    await expect(page.locator('.photo-tile')).toHaveCount(3);
    await expect(page.locator('.photo-tile .tile-controls')).toHaveCount(0);
    await expect(page.locator('#reorderBtn')).toHaveCount(0);
    await expect(page.locator('#refreshDatesBtn')).toHaveCount(0);
    // The estimated-date marker is only for people who can edit the photo.
    await expect(page.locator('.photo-tile .tile-date-est')).toHaveCount(0);
    await expect(page.locator('.photo-tile').last()).not.toHaveAttribute('data-taken-text', /estimated/);
    // Direct API call is refused too.
    const csrf = await page.locator('#photoGrid').getAttribute('data-csrf');
    const photoId = await page.locator('.photo-tile').first().getAttribute('data-photo-id');
    const res = await page.request.post('/event_photo_delete.php', { form: { csrf, photo_id: photoId } });
    expect(res.status()).toBe(403);
  });

  test('admin can select several photos, drag them as a group, and reset to chronological', async ({ page }) => {
    test.skip(!R2, 'photo storage not configured');
    await helpers.loginAsAdmin();
    await page.goto(`/event_photos.php?event_id=${eventId}`);
    const ids = await page.locator('.photo-tile').evaluateAll((els) => els.map((e) => e.getAttribute('data-photo-id')));
    expect(ids).toHaveLength(3);

    await page.locator('#reorderBtn').click();
    await expect(page.locator('#photoGrid')).toHaveClass(/reordering/);
    await expect(page.locator('#reorderBar')).toBeVisible();
    await expect(page.locator('.photo-tile .move-left')).toHaveCount(0); // arrows are gone

    // Select photos 2 and 3 (click, then shift-click), then drag photo 2 to before photo 1.
    const t = (i) => page.locator('.photo-tile').nth(i);
    await t(1).click();
    await t(2).click({ modifiers: ['Shift'] });
    await expect(page.locator('#selectionCount')).toHaveText('2 selected');
    const from = await t(1).boundingBox();
    const to = await t(0).boundingBox();
    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(from.x + from.width / 2 + 20, from.y + from.height / 2 + 10, { steps: 4 });
    await page.mouse.move(to.x + 10, to.y + to.height / 2, { steps: 8 });
    await expect(page.locator('.drag-ghost .count')).toHaveText('2');
    await expect(t(0)).toHaveClass(/drop-before/);
    await page.mouse.up();
    let order = await page.locator('.photo-tile').evaluateAll((els) => els.map((e) => e.getAttribute('data-photo-id')));
    expect(order).toEqual([ids[1], ids[2], ids[0]]); // group moved, relative order kept

    await page.locator('#saveOrderBtn').click();
    await expect(page.locator('#resetOrderBtn')).toBeVisible({ timeout: 10000 });
    order = await page.locator('.photo-tile').evaluateAll((els) => els.map((e) => e.getAttribute('data-photo-id')));
    expect(order).toEqual([ids[1], ids[2], ids[0]]);

    // Move to end with the toolbar, then reset.
    await page.locator('#reorderBtn').click();
    await t(0).click();
    await page.locator('#moveEndBtn').click();
    order = await page.locator('.photo-tile').evaluateAll((els) => els.map((e) => e.getAttribute('data-photo-id')));
    expect(order).toEqual([ids[2], ids[0], ids[1]]);
    await page.locator('#cancelOrderBtn').click();
    order = await page.locator('.photo-tile').evaluateAll((els) => els.map((e) => e.getAttribute('data-photo-id')));
    expect(order).toEqual([ids[1], ids[2], ids[0]]); // cancel restored the saved order

    page.once('dialog', (d) => d.accept());
    await page.locator('#resetOrderBtn').click();
    await expect(page.locator('#resetOrderBtn')).toHaveCount(0, { timeout: 10000 });
    const reset = await page.locator('.photo-tile').evaluateAll((els) => els.map((e) => e.getAttribute('data-photo-id')));
    expect(reset).toEqual(ids);
  });

  test('refreshing estimated dates keeps moved photos in place after a chronological reset', async ({ page }) => {
    test.skip(!R2, 'photo storage not configured');
    await helpers.loginAsAdmin();
    await page.goto(`/event_photos.php?event_id=${eventId}`);
    const ids = await page.locator('.photo-tile').evaluateAll((els) => els.map((e) => e.getAttribute('data-photo-id')));
    const pngId = ids[2]; // the estimated one sits last in chronological order
    await expect(page.locator('.photo-tile').last()).toHaveAttribute('data-estimated', '1');
    await expect(page.locator('#refreshDatesBtn')).toContainText('(1)');

    // Move the estimated photo to the front by hand and save.
    const t = (i) => page.locator('.photo-tile').nth(i);
    await page.locator('#reorderBtn').click();
    await t(2).click();
    await page.locator('#moveStartBtn').click();
    await page.locator('#saveOrderBtn').click();
    await expect(page.locator('#resetOrderBtn')).toBeVisible({ timeout: 10000 });

    // Refresh estimated dates, then reset to chronological: it must stay first.
    page.once('dialog', (d) => d.accept());
    await page.locator('#refreshDatesBtn').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#refreshDatesBtn')).toBeVisible({ timeout: 10000 }); // still estimated
    page.once('dialog', (d) => d.accept());
    await page.locator('#resetOrderBtn').click();
    await expect(page.locator('#resetOrderBtn')).toHaveCount(0, { timeout: 10000 });
    const after = await page.locator('.photo-tile').evaluateAll((els) => els.map((e) => e.getAttribute('data-photo-id')));
    expect(after).toEqual([pngId, ids[0], ids[1]]);
    await expect(page.locator('.photo-tile').first()).toHaveAttribute('data-taken-text', /Sep 12, 2026 10:04 AM \(estimated\)/);
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
