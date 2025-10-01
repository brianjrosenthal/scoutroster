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
});
