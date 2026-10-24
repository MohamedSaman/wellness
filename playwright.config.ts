import { defineConfig, devices } from '@playwright/test';

/**
 * The till's end-to-end smoke tests (tests/e2e): composer test:e2e, or
 * npx playwright test after npm run build. Playwright starts the app on its own
 * port with a freshly seeded demo clinic (scripts/e2e-server.sh) and stops it
 * when the run ends. One worker: the tests share the clinic's tills and shifts.
 */
const port = Number(process.env.E2E_PORT ?? 8010);

export default defineConfig({
    testDir: 'tests/e2e',
    outputDir: 'storage/e2e/results',
    workers: 1,
    forbidOnly: !!process.env.CI,
    reporter: [
        ['list'],
        ['html', { outputFolder: 'storage/e2e/report', open: 'never' }],
    ],
    timeout: 120_000,
    expect: { timeout: 10_000 },
    use: {
        baseURL: `http://127.0.0.1:${port}`,
        // The till's service worker caches pages; each test starts from the server.
        serviceWorkers: 'block',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'sign-in', testMatch: /\.setup\.ts$/ },
        {
            name: 'chromium',
            dependencies: ['sign-in'],
            use: {
                ...devices['Desktop Chrome'],
                storageState: 'storage/e2e/owner.json',
            },
        },
        {
            name: 'firefox',
            dependencies: ['sign-in'],
            use: {
                ...devices['Desktop Firefox'],
                storageState: 'storage/e2e/owner.json',
            },
        },
    ],
    webServer: {
        command: 'sh scripts/e2e-server.sh',
        url: `http://127.0.0.1:${port}/up`,
        env: { E2E_PORT: String(port) },
        reuseExistingServer: false,
        timeout: 120_000,
    },
});
