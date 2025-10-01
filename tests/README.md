# Cub Scout Web Application - Browser Tests

This directory contains comprehensive browser tests for the Cub Scout web application using Playwright. The tests cover the most important user flows including viewing the homepage, creating events, and RSVPing to events.

## Test Coverage

### 1. Homepage Tests (`specs/homepage.spec.js`)
- ✅ Display homepage for logged-in users
- ✅ Redirect to login when not authenticated  
- ✅ Display family information correctly
- ✅ Handle RSVP section for upcoming events
- ✅ Display reimbursement section for approvers
- ✅ Handle profile completion prompts
- ✅ Display volunteer opportunities when available
- ✅ Show registration section when applicable

### 2. Event Creation Tests (`specs/event-creation.spec.js`)
- ✅ Create a new event as admin
- ✅ Require authentication for event creation
- ✅ Require admin privileges for event creation
- ✅ Validate required fields
- ✅ Edit existing events
- ✅ Handle datetime inputs correctly
- ✅ Handle image upload for events
- ✅ Handle optional fields correctly
- ✅ Navigate back to events list

### 3. Event RSVP Tests (`specs/event-rsvp.spec.js`)
- ✅ RSVP Yes to an event
- ✅ RSVP Maybe to an event
- ✅ RSVP No to an event
- ✅ Edit existing RSVP
- ✅ Show RSVP counts on event page
- ✅ Handle Evite URL override
- ✅ Require login for RSVP
- ✅ Handle modal close and reopen
- ✅ Handle guest count validation

## Prerequisites

1. **Node.js** (version 16 or higher)
2. **Your Cub Scout web application** running locally or accessible via URL
3. **Test user accounts** in your application:
   - An admin user account
   - A regular user account

## Setup

1. **Install dependencies:**
   ```bash
   cd tests
   npm install
   ```

2. **Install Playwright browsers:**
   ```bash
   npm run install-browsers
   ```

3. **Configure test environment:**
   
   Copy the environment configuration file:
   ```bash
   cp .env.example .env
   ```
   
   Edit `.env` to match your setup:
   ```env
   # Base URL of your application
   BASE_URL=http://localhost:8080
   
   # Test user credentials
   ADMIN_EMAIL=admin@yourdomain.com
   ADMIN_PASSWORD=your_super_password_or_regular_password
   
   USER_EMAIL=testuser@yourdomain.com  
   USER_PASSWORD=testuser123
   ```

4. **Start your web application:**
   Make sure your Cub Scout web application is running and accessible at the BASE_URL you configured.

## Running Tests

### Run all tests
```bash
npm test
```

### Run tests with browser visible (headed mode)
```bash
npm run test:headed
```

### Run specific test file
```bash
npx playwright test specs/homepage.spec.js
```

### Run tests in debug mode
```bash
npm run test:debug
```

### Run tests with UI mode (interactive)
```bash
npm run test:ui
```

### View test report
```bash
npm run test:report
```

## Test Configuration

The tests are configured in `playwright.config.js` with the following settings:

- **Base URL**: Configurable via environment variable or defaults to `http://localhost:8080`
- **Browsers**: Tests run on Chromium, Firefox, and Safari/WebKit
- **Screenshots**: Captured on test failure
- **Video**: Recorded for failed tests
- **Traces**: Collected on retry for debugging

## Test Data and Cleanup

### Test Users
The tests expect these user accounts to exist in your application:
- **Admin user**: Can create events, access admin pages
- **Regular user**: Can view homepage, RSVP to events

### Test Data Generation
- Event names are generated with timestamps to ensure uniqueness
- Tests create temporary events for RSVP testing
- All test data uses clearly identifiable names (prefixed with "Test")

### Cleanup
Currently, test cleanup is minimal. Consider implementing:
- Database cleanup between test runs
- Automatic deletion of test events
- Reset of test user RSVPs

## Customizing Tests

### Adding New Tests
1. Create a new `.spec.js` file in the `specs/` directory
2. Use the `TestHelpers` class for common operations:
   ```javascript
   const { test, expect } = require('@playwright/test');
   const { TestHelpers } = require('../utils/test-helpers');
   
   test.describe('My New Tests', () => {
     let helpers;
     
     test.beforeEach(async ({ page }) => {
       helpers = new TestHelpers(page);
     });
     
     test('should do something', async ({ page }) => {
       await helpers.loginAsUser();
       // Your test code here
     });
   });
   ```

### Modifying Test Credentials
Update the login methods in `utils/test-helpers.js`:
- `loginAsAdmin()` - for admin user login
- `loginAsUser()` - for regular user login

### Adding Test Utilities
Add new helper methods to the `TestHelpers` class in `utils/test-helpers.js`.

## Troubleshooting

### Tests fail with "Login failed"
- Verify your test user credentials in `.env`
- Check that your application is running at the BASE_URL
- Ensure the login form uses the expected field names (`email`, `password`)

### Tests timeout or fail to find elements
- Your application might be slower than expected
- Increase timeouts in `playwright.config.js`
- Check that CSS selectors match your application's markup

### Browser crashes or errors
- Update Playwright browsers: `npm run install-browsers`
- Check system resources (memory, disk space)
- Try running tests in headed mode to see what's happening: `npm run test:headed`

### Database state issues
- Tests might interfere with each other if running in parallel
- Consider setting up test data isolation
- Implement proper cleanup between tests

## Best Practices

1. **Keep tests independent**: Each test should be able to run in isolation
2. **Use descriptive names**: Test names should clearly indicate what is being tested
3. **Handle async operations**: Always await page operations and use proper waits
4. **Take screenshots**: Use `helpers.takeScreenshot()` for debugging
5. **Clean up test data**: Implement cleanup to avoid test pollution
6. **Mock external services**: Consider mocking email sending, payment processing, etc.

## Environment Variables Reference

| Variable | Description | Default |
|----------|-------------|---------|
| `BASE_URL` | Base URL of your application | `http://localhost:8080` |
| `ADMIN_EMAIL` | Email for admin test user | `admin@example.com` |
| `ADMIN_PASSWORD` | Password for admin test user | `super` |
| `USER_EMAIL` | Email for regular test user | `user@example.com` |
| `USER_PASSWORD` | Password for regular test user | `password123` |

## Contributing

When adding new tests:
1. Follow the existing test structure and patterns
2. Add appropriate documentation
3. Update this README if adding new test categories
4. Ensure tests are reliable and don't depend on external factors
5. Add screenshots for complex interactions

## Support

For issues with these tests:
1. Check the troubleshooting section above
2. Review Playwright documentation: https://playwright.dev/
3. Check application logs for server-side errors
4. Use debug mode to step through failing tests
