const { expect } = require('@playwright/test');
require('dotenv').config({ path: require('path').join(__dirname, '../.env') });

/**
 * Test helper utilities for Cub Scout application
 */
class TestHelpers {
  constructor(page) {
    this.page = page;
  }

  /**
   * Login as a user with the given credentials
   */
  async login(email, password) {
    await this.page.goto('/login.php');
    await this.page.fill('input[name="email"]', email);
    await this.page.fill('input[name="password"]', password);
    await this.page.click('button[type="submit"]');
    
    // Wait for redirect to home page or handle login failure
    await this.page.waitForLoadState('networkidle');
    
    // Check if we're on the home page (successful login) or still on login page (failed)
    const currentUrl = this.page.url();
    if (currentUrl.includes('/login.php')) {
      throw new Error('Login failed - still on login page');
    }
  }

  /**
   * Login as admin user using super password (if available in config)
   */
  async loginAsAdmin(adminEmail = process.env.ADMIN_EMAIL || 'admin@example.com', superPassword = process.env.ADMIN_PASSWORD || process.env.SUPER_PASSWORD || 'super') {
    await this.page.goto('/login.php');
    await this.page.fill('input[name="email"]', adminEmail);
    await this.page.fill('input[name="password"]', superPassword);
    await this.page.click('button[type="submit"]');
    await this.page.waitForLoadState('networkidle');
  }

  /**
   * Login as regular user
   */
  async loginAsUser(userEmail = process.env.USER_EMAIL || 'user@example.com', password = process.env.USER_PASSWORD || 'password123') {
    if (!process.env.USER_EMAIL || !process.env.USER_PASSWORD) {
      throw new Error('USER_EMAIL and USER_PASSWORD must be set in .env file');
    }
    await this.login(userEmail, password);
  }

  /**
   * Logout current user
   */
  async logout() {
    // Simply navigate directly to logout page - much simpler and more reliable
    await this.page.goto('/logout.php');
    await this.page.waitForLoadState('networkidle');
  }

  /**
   * Fill datetime-local input with a date/time
   */
  async fillDateTime(selector, dateTimeString) {
    // Convert date string to datetime-local format (YYYY-MM-DDTHH:MM)
    const date = new Date(dateTimeString);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    
    const datetimeLocal = `${year}-${month}-${day}T${hours}:${minutes}`;
    await this.page.fill(selector, datetimeLocal);
  }

  /**
   * Wait for a success flash message
   */
  async waitForFlashMessage(expectedMessage = null) {
    await this.page.waitForSelector('.flash', { timeout: 5000 });
    if (expectedMessage) {
      await expect(this.page.locator('.flash')).toContainText(expectedMessage);
    }
  }

  /**
   * Wait for an error message
   */
  async waitForErrorMessage(expectedMessage = null) {
    await this.page.waitForSelector('.error', { timeout: 5000 });
    if (expectedMessage) {
      await expect(this.page.locator('.error')).toContainText(expectedMessage);
    }
  }

  /**
   * Check if user is on the login page (not authenticated)
   */
  async isOnLoginPage() {
    return this.page.url().includes('/login.php');
  }

  /**
   * Generate a unique test event name
   */
  getUniqueEventName() {
    const timestamp = new Date().getTime();
    return `Test Event ${timestamp}`;
  }

  /**
   * Generate future date for event testing
   */
  getFutureDate(daysFromNow = 7) {
    const date = new Date();
    date.setDate(date.getDate() + daysFromNow);
    return date;
  }

  /**
   * Clean up test data - delete events created during testing
   */
  async cleanupTestEvents() {
    // This would need to be implemented based on your cleanup strategy
    // Could involve direct database access or API calls
    console.log('Cleanup test events - implement based on your needs');
  }

  /**
   * Take a screenshot with a descriptive name
   */
  async takeScreenshot(name) {
    await this.page.screenshot({ 
      path: `test-results/screenshots/${name}-${Date.now()}.png`,
      fullPage: true 
    });
  }

  /**
   * Wait for modal to appear and be visible
   */
  async waitForModal(modalSelector = '.modal:not(.hidden)') {
    await this.page.waitForSelector(modalSelector, { state: 'visible', timeout: 5000 });
  }

  /**
   * Close modal by clicking the close button or overlay
   */
  async closeModal() {
    // Try close button first
    const closeButton = this.page.locator('.modal .close').first();
    if (await closeButton.isVisible()) {
      await closeButton.click();
    } else {
      // Try clicking on modal overlay
      await this.page.click('.modal');
    }
    
    // Wait for modal to be hidden
    await this.page.waitForSelector('.modal.hidden', { timeout: 5000 });
  }

  /**
   * Fill CSRF token automatically (if needed)
   */
  async fillCSRFToken(formSelector = 'form') {
    // CSRF tokens are typically already embedded in forms in PHP applications
    // This is a placeholder in case manual CSRF handling is needed
  }

  /**
   * Create a test event using PHP script
   * Returns the event ID for cleanup
   */
  async createTestEventViaPhp() {
    const { execSync } = require('child_process');
    const path = require('path');
    
    try {
      const scriptPath = path.join(__dirname, 'create-test-event.php');
      const result = execSync(`php "${scriptPath}"`, { 
        encoding: 'utf8',
        cwd: process.cwd()
      });
      
      const response = JSON.parse(result.trim());
      
      if (!response.success) {
        throw new Error(response.error || 'Failed to create test event');
      }
      
      console.log(`Created test event: ${response.event_name} (ID: ${response.event_id})`);
      return response.event_id;
      
    } catch (error) {
      console.error('Failed to create test event:', error.message);
      throw error;
    }
  }

  /**
   * Delete a test event using PHP script
   * Returns true on success
   */
  async deleteTestEvent(eventId) {
    if (!eventId) {
      console.warn('No event ID provided for deletion');
      return false;
    }
    
    const { execSync } = require('child_process');
    const path = require('path');
    
    try {
      const scriptPath = path.join(__dirname, 'delete-test-event.php');
      const result = execSync(`php "${scriptPath}" ${eventId}`, { 
        encoding: 'utf8',
        cwd: process.cwd()
      });
      
      const response = JSON.parse(result.trim());
      
      if (!response.success) {
        console.warn(`Failed to delete test event ${eventId}:`, response.error);
        return false;
      }
      
      console.log(`Deleted test event: ${response.event_name} (ID: ${eventId})`);
      return true;
      
    } catch (error) {
      console.warn(`Failed to delete test event ${eventId}:`, error.message);
      return false;
    }
  }

  /**
   * Clean up any leftover test events (safety net)
   * Deletes all events with names containing "Test Event" or "TEST_EVENT_"
   */
  async cleanupAllTestEvents() {
    const { execSync } = require('child_process');
    const path = require('path');
    
    try {
      const scriptPath = path.join(__dirname, 'cleanup-test-events.php');
      const result = execSync(`php "${scriptPath}"`, { 
        encoding: 'utf8',
        cwd: process.cwd()
      });
      
      const response = JSON.parse(result.trim());
      
      if (!response.success) {
        console.warn('Failed to cleanup test events:', response.error);
        return false;
      }
      
      if (response.deleted_count > 0) {
        console.log(`Cleaned up ${response.deleted_count} test events:`);
        response.deleted_events.forEach(event => {
          console.log(`  - ${event.name} (ID: ${event.id})`);
        });
      } else {
        console.log('No test events found to cleanup');
      }
      
      return true;
      
    } catch (error) {
      console.warn('Failed to cleanup test events:', error.message);
      return false;
    }
  }
}

module.exports = { TestHelpers };
