import { mkdirSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { addItem, dialog, expectInside, useTill } from './helpers';

/**
 * The clinic dashboard (/admin) in the Clinic Teal theme: rings up a sale at
 * General Pharmacy (T1) and an OPD bill at the doctor's (T2) so the widgets
 * have real figures, then checks that nothing sticks out of the page or
 * scrolls sideways at laptop, desktop and tablet widths, light and dark.
 * In Chromium it also saves screenshots for review to
 * storage/app/screenshots (not committed).
 */

const viewports = [
    { width: 768, height: 1024 },
    { width: 1280, height: 800 },
    { width: 1440, height: 900 },
    { width: 1920, height: 1080 },
];

const screenshots = 'storage/app/screenshots';

/** Pays the cart in cash, exactly, and starts a new sale. */
async function payExact(page: Page): Promise<void> {
    await page.locator('[data-test=pay-button]').click();
    const pay = dialog(page, 'Payment');
    await expect(pay).toBeVisible();
    await pay.getByRole('button', { name: 'Exact' }).click();
    await pay.locator('[data-test=complete-sale]').click();
    const done = page.locator('[data-test=sale-done]');
    await expect(done).toBeVisible();
    await done.locator('[data-test=new-sale]').click();
    await expect(done).toBeHidden();
}

/** Opens the dashboard in light or dark mode (Filament's own switch) and waits for every lazy widget. */
async function openDashboard(
    page: Page,
    mode: 'light' | 'dark',
): Promise<void> {
    await page.addInitScript((theme) => {
        window.localStorage.setItem('theme', theme);
    }, mode);
    await page.goto('/admin');
    await expect(page.locator('html')).toHaveAttribute(
        'data-pos-theme',
        'clinic_teal',
    );

    // The widgets load when they scroll into view (lazy): scroll the whole page through, then back.
    for (let step = 0; step < 15; step++) {
        await page.mouse.wheel(0, 700);
        await page.waitForTimeout(150);
    }

    await page.waitForLoadState('networkidle');
    await page.evaluate(() => window.scrollTo(0, 0));

    for (const widget of [
        'greeting-hero',
        'todays-insights',
        'location-performance',
        'stock-value',
        'stock-alerts',
        'activity',
    ]) {
        await expect(page.locator(`[data-test=${widget}]`)).toBeVisible();
    }

    await expect(page.getByText("Today's OPD bills")).toBeVisible();
    await expect(page.locator('.fi-wi-chart canvas')).toBeVisible();
    for (const kpi of [
        "Today's sales",
        'OPD bills today',
        'Low-stock items',
        'Expiring ≤ 30 days',
    ]) {
        await expect(page.getByText(kpi, { exact: true })).toBeVisible();
    }
    // Every Livewire request (the lazy widgets) has finished.
    await page.waitForLoadState('networkidle');
}

test.describe.configure({ mode: 'serial' });

test('ring up a sale and an OPD bill for the dashboard', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/pos');

    await useTill(page, 'T2');
    await addItem(page, 'Panadol');
    await page.locator('[data-test=medicines-amount]').fill('1250.00');
    await payExact(page);

    await page.getByRole('button', { name: 'Change till' }).click();
    await useTill(page, 'T1');
    await addItem(page, 'Panadol');
    await payExact(page);

    // Signing in opened the dashboard, and its figures are kept for a minute
    // (ClinicDashboardService::CACHE_SECONDS): wait until these sales count.
    await page.waitForTimeout(61_000);
});

for (const viewport of viewports) {
    for (const mode of ['light', 'dark'] as const) {
        test(`the dashboard fits at ${viewport.width}×${viewport.height}, ${mode}`, async ({
            page,
            browserName,
        }) => {
            await page.setViewportSize(viewport);
            await openDashboard(page, mode);

            if (mode === 'dark') {
                await expect(page.locator('html')).toHaveClass(/\bdark\b/);
            }

            await expect(
                page.locator('[data-test=greeting-summary]'),
            ).toContainText('OPD bill');
            await expectInside(
                page.locator('.fi-main'),
                `dashboard ${viewport.width} ${mode}`,
                {
                    window: 'width',
                },
            );

            if (
                browserName === 'chromium' &&
                [768, 1440].includes(viewport.width)
            ) {
                mkdirSync(screenshots, { recursive: true });
                await page.screenshot({
                    path: `${screenshots}/dashboard-${mode}-${viewport.width}.png`,
                    fullPage: true,
                });
            }
        });
    }
}
