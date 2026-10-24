import { expect, test as setup } from '@playwright/test';

/**
 * Signs the demo clinic's owner in once per run and keeps the session for
 * every test (signing in per test runs into the login rate limit).
 */
setup('sign in as the clinic owner', async ({ page }) => {
    await page.goto('/login');
    await page.locator('input[name=email]').fill('owner@clinic.test');
    await page.locator('input[name=password]').fill('password');
    await page.locator('[data-test=login-button]').click();
    await expect(page).not.toHaveURL(/\/login/);
    await page.context().storageState({ path: 'storage/e2e/owner.json' });
});
