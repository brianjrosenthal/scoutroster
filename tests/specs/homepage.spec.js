const { test, expect } = require('@playwright/test');
const { TestHelpers } = require('../utils/test-helpers');

test.describe('Homepage Tests', () => {
  let helpers;

  test.beforeEach(async ({ page }) => {
    helpers = new TestHelpers(page);
  });

  test('should redirect to login when not authenticated', async ({ page }) => {
    // Try to access homepage without logging in
    await page.goto('/index.php');
    
    // Should be redirected to login page
    await expect(page).toHaveURL(/.*login\.php/);
    
    // Verify login form is present
    await expect(page.locator('input[name="email"]')).toBeVisible();
    await expect(page.locator('input[name="password"]')).toBeVisible();
    await expect(page.locator('button[type="submit"]')).toBeVisible();
  });

  test('should handle RSVP section for upcoming events', async ({ page }) => {
    let testEventId = null;
    
    try {
      // Create a test event using PHP
      testEventId = await helpers.createTestEventViaPhp();
      
      // Login as regular user
      await helpers.loginAsUser();
      
      // Navigate to homepage
      await page.goto('/index.php');
      
      // Check that we're on the homepage
      await expect(page.locator('h2')).toContainText('Welcome back');
      
      // Look for upcoming events section
      await expect(page.locator('h3:has-text("Upcoming Events")')).toBeVisible();
      
      // Verify event cards are displayed properly
      const eventCards = page.locator('.card h3 a[href*="/event.php"]');
      const eventCount = await eventCards.count();
      expect(eventCount).toBeGreaterThan(0);
      
      // Check that our test event appears - use .first() to get the specific event card
      const testEventCard = page.locator('.card h3:has(a[href*="/event.php"]):has-text("TEST_EVENT_")').locator('..').first();
      await expect(testEventCard).toBeVisible();
      
      // Should have when/where information
      await expect(testEventCard.locator('text=When:')).toBeVisible();
      await expect(testEventCard.locator('text=Where:')).toBeVisible();
      await expect(testEventCard.locator('text=Test Location for Automated Testing')).toBeVisible();
      
      // Should have action buttons (View and RSVP since user hasn't RSVP'd yet)
      const viewButton = testEventCard.locator('a:has-text("View")');
      const rsvpButton = testEventCard.locator('a:has-text("RSVP")');
      
      await expect(viewButton).toBeVisible();
      await expect(rsvpButton).toBeVisible();
      
      // Should NOT have Evite button (since our test event has no Evite URL)
      const eviteButton = testEventCard.locator('a:has-text("RSVP TO EVITE")');
      await expect(eviteButton).not.toBeVisible();
      
      // Verify the RSVP button links to the event page with RSVP parameter
      const rsvpHref = await rsvpButton.getAttribute('href');
      expect(rsvpHref).toMatch(/event\.php\?id=\d+&open_rsvp=1/);
      
      // Take screenshot for verification
      await helpers.takeScreenshot('homepage-upcoming-events-with-rsvp');
      
    } finally {
      // Always cleanup test event, even if test fails
      if (testEventId) {
        await helpers.deleteTestEvent(testEventId);
      }
    }
  });
});
