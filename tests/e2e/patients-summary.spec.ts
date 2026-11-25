import { mkdirSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { addItem, dialog, expectInside, useTill } from './helpers';

/**
 * The summary above the Patients list (docs/PHARMACY.md, branch 20): rings
 * up an OPD bill and a pharmacy bill for a patient, then checks that the
 * cards and charts fit beside the sidebar at laptop and desktop widths in
 * light and dark, that filtering and searching the list changes them
 * without a reload, and that a folded summary stays folded. In Chromium it
 * also saves screenshots to storage/app/screenshots (not committed).
 */

const viewports = [
    { width: 1280, height: 800 },
    { width: 1440, height: 900 },
    { width: 1920, height: 1080 },
];

const screenshots = 'storage/app/screenshots';

/** Pays the cart in cash, exactly, for Sunil Fernando (the demo clinic's patient). */
async function payFor(page: Page): Promise<void> {
    const step = page.locator('[data-test=prescription-dialog]');

    await page.locator('[data-test=pay-button]').click();
    await expect(step).toBeVisible();
    await step.locator('[data-test=patient-name]').fill('Sunil');
    await expect(step.locator('[data-test=patient-result]')).toHaveCount(1);
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    await expect(step.locator('[data-test=patient-chosen]')).toContainText(
        'Sunil Fernando',
    );
    await step.locator('[data-test=prescription-continue]').click();

    const pay = dialog(page, 'Payment');
    await expect(pay).toBeVisible();
    await pay.getByRole('button', { name: 'Exact' }).click();
    await pay.locator('[data-test=complete-sale]').click();
    const done = page.locator('[data-test=sale-done]');
    await expect(done).toBeVisible();
    await done.locator('[data-test=new-sale]').click();
    await expect(done).toBeHidden();
}

function card(page: Page, name: string): Locator {
    return page.locator(
        `[data-test=summary-${name}] .fi-wi-stats-overview-stat-value`,
    );
}

async function openList(
    page: Page,
    mode: 'light' | 'dark',
    query = '',
): Promise<Locator> {
    await page.addInitScript((theme) => {
        window.localStorage.setItem('theme', theme);
    }, mode);
    await page.goto(`/admin/patients${query}`);
    const summary = page.locator('[data-test=patients-summary]');
    await expect(summary).toBeVisible();

    return summary;
}

async function count(page: Page, name: string): Promise<number> {
    return Number((await card(page, name).innerText()).replace(/\D/g, ''));
}

test.describe.configure({ mode: 'serial' });

test('ring up an OPD bill and a pharmacy bill for a patient', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/pos');

    await useTill(page, 'T2');
    await addItem(page, 'Panadol');
    await page.locator('[data-test=medicines-amount]').fill('1250.00');
    await payFor(page);

    await page.getByRole('button', { name: 'Change till' }).click();
    await useTill(page, 'T1');
    await addItem(page, 'Panadol');
    await payFor(page);
});

for (const viewport of viewports) {
    for (const mode of ['light', 'dark'] as const) {
        test(`the summary fits at ${viewport.width}×${viewport.height}, ${mode}`, async ({
            page,
            browserName,
        }) => {
            await page.setViewportSize(viewport);
            const summary = await openList(page, mode);

            await expect(
                summary.getByRole('heading', { name: 'Today', exact: true }),
            ).toBeVisible();
            for (const name of [
                'patients',
                'visits',
                'visits-per-patient',
                'new-returning',
                'opd-pharmacy',
                'by-location',
                'child-adult-senior',
                'consultations-services',
                'medicines',
            ]) {
                await expect(card(page, name)).toBeVisible();
            }
            await expect(summary.locator('canvas')).toHaveCount(2);
            await expect(summary.locator('canvas').first()).toBeVisible();
            await page.waitForLoadState('networkidle');

            await expectInside(
                summary,
                `patients summary ${viewport.width} ${mode}`,
                { window: 'width' },
            );

            // No figure or amount is cut off on its card.
            const cut = await summary
                .locator('.fi-wi-stats-overview-stat-value')
                .evaluateAll((values) =>
                    values
                        .filter(
                            (value) =>
                                value.scrollWidth > value.clientWidth + 1,
                        )
                        .map((value) => value.textContent?.trim()),
                );
            expect(cut).toEqual([]);

            if (browserName === 'chromium') {
                mkdirSync(screenshots, { recursive: true });
                await page.screenshot({
                    path: `${screenshots}/patients-summary-${mode}-${viewport.width}.png`,
                    fullPage: true,
                });
            }
        });
    }
}

test('the summary follows the filters and the search without a reload', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const summary = await openList(page, 'light');
    const visits = await count(page, 'visits');
    expect(visits).toBeGreaterThanOrEqual(2);
    const bars = summary.locator('.fi-wi-chart-assistive-content').first();
    await expect(bars).toContainText('OPD');
    await expect(bars).toContainText('Pharmacy');

    // The bill type filter, applied as a doctor would.
    const billType = page.getByLabel('Bill type');

    if (!(await billType.isVisible())) {
        await page.getByRole('button', { name: 'Filter', exact: true }).click();
    }

    await billType.selectOption({ label: 'OPD consultation' });
    await page.getByRole('button', { name: 'Apply filters' }).click();
    await expect(
        summary.getByRole('heading', { name: 'All time · OPD consultation' }),
    ).toBeVisible();
    await expect(card(page, 'opd-pharmacy')).toHaveText(/^\s*\d+ · 0\s*$/);
    await expect(bars).not.toContainText('Pharmacy');
    await expect(card(page, 'new-returning')).toHaveText(/^\s*–\s*$/);

    await page.getByRole('searchbox').last().fill('no such patient');
    await expect(
        summary.getByRole('heading', { name: /Search: no such patient/ }),
    ).toBeVisible();
    await expect(card(page, 'patients')).toHaveText(/^\s*0\s*$/);
    await expect(summary.getByText('No visits').first()).toBeVisible();

    await page.getByRole('searchbox').last().fill('sunil');
    await expect(card(page, 'patients')).toHaveText(/^\s*1\s*$/);
    await expect(summary.locator('canvas').first()).toBeVisible();
});

test('a folded summary stays folded after a reload', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const summary = await openList(page, 'light');

    await expect(card(page, 'patients')).toBeVisible();
    await summary.getByRole('heading', { name: 'Today', exact: true }).click();
    await expect(card(page, 'patients')).toBeHidden();

    await page.reload();
    await expect(
        summary.getByRole('heading', { name: 'Today', exact: true }),
    ).toBeVisible();
    await expect(card(page, 'patients')).toBeHidden();

    await summary.getByRole('heading', { name: 'Today', exact: true }).click();
    await expect(card(page, 'patients')).toBeVisible();
    await expect(summary.locator('canvas').first()).toBeVisible();
});
