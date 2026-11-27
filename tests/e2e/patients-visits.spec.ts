import { mkdirSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import {
    addItem,
    expectInside,
    openPatients,
    payForPatient,
    useTill,
} from './helpers';

/**
 * The Patients list's state, Visits tab and quick view (docs/PHARMACY.md,
 * branch 21): rings up an OPD bill and a pharmacy bill for a patient, then
 * checks that the filters and search survive opening the patient and coming
 * back by the sidebar and the breadcrumb, that Reset filters clears them,
 * and that the Visits tab and the quick view fit at laptop and desktop
 * widths in light and dark with nothing outside its box. In Chromium it
 * also saves screenshots to storage/app/screenshots (not committed).
 */

const viewports = [
    { width: 1280, height: 800 },
    { width: 1440, height: 900 },
    { width: 1920, height: 1080 },
];

const screenshots = 'storage/app/screenshots';

function theme(page: Page, mode: 'light' | 'dark'): Promise<void> {
    return page.addInitScript((value) => {
        window.localStorage.setItem('theme', value);
    }, mode);
}

/** The bill type filter, applied as a doctor would. */
async function filterBillType(page: Page, label: string): Promise<void> {
    const billType = page.getByLabel('Bill type');

    if (!(await billType.isVisible())) {
        await page.getByRole('button', { name: 'Filter', exact: true }).click();
    }

    await billType.selectOption({ label });
    await page.getByRole('button', { name: 'Apply filters' }).click();
}

function search(page: Page): Locator {
    return page.getByRole('searchbox').last();
}

function quickView(page: Page): Locator {
    return page
        .locator('.fi-modal-window')
        .filter({ has: page.locator('[data-test=patient-quick-view]') });
}

/** The list keeps OPD consultation and the search "sunil". */
async function expectKept(page: Page): Promise<void> {
    await expect(page).toHaveURL(/\/admin\/patients(\?|$)/);
    await expect(page.getByText('Bill type: OPD consultation')).toBeVisible();
    await expect(search(page)).toHaveValue('sunil');
    await expect(
        page
            .locator('[data-test=patients-summary]')
            .getByRole('heading', { name: /OPD consultation · Search: sunil/ }),
    ).toBeVisible();
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
    await payForPatient(page, 'Sunil', 'Sunil Fernando');

    await page.getByRole('button', { name: 'Change till' }).click();
    await useTill(page, 'T1');
    await addItem(page, 'Panadol');
    await payForPatient(page, 'Sunil', 'Sunil Fernando');
});

test('the filters and the search survive opening the patient and coming back', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await openPatients(page);

    await filterBillType(page, 'OPD consultation');
    await search(page).fill('sunil');
    const row = page.locator('tbody tr.fi-ta-row', {
        hasText: 'Sunil Fernando',
    });
    await expect(row).toHaveCount(1);

    // A row opens the quick view: the patient's OPD bills only, no page change.
    await row.getByText('Sunil Fernando').click();
    const panel = quickView(page);
    await expect(panel).toBeVisible();
    await expect(
        panel.getByRole('heading', { name: 'Sunil Fernando' }),
    ).toBeVisible();
    await expect(
        panel.locator('[data-test=quick-view-bill]').first(),
    ).toContainText('OPD');
    await expect(
        panel.locator('[data-test=quick-view-bill]', { hasText: 'Pharmacy' }),
    ).toHaveCount(0);
    await expect(panel).toContainText('Panadol');
    await expect(page).toHaveURL(/\/admin\/patients(\?|$)/);

    // Its full page, then back by the sidebar.
    await panel.getByRole('link', { name: 'Open full page' }).click();
    await expect(page).toHaveURL(/\/admin\/patients\/[^/?]+$/);
    await page
        .locator('.fi-sidebar')
        .getByRole('link', { name: 'Patients', exact: true })
        .click();
    await expectKept(page);

    // And by the breadcrumb.
    await page.goto(page.url());
    await row.getByRole('link', { name: 'Open' }).click();
    await expect(page).toHaveURL(/\/admin\/patients\/[^/?]+$/);
    await page
        .locator('.fi-breadcrumbs')
        .getByRole('link', { name: 'Patients', exact: true })
        .click();
    await expectKept(page);

    // The Visits tab shares them.
    await page.getByRole('tab', { name: 'Visits' }).click();
    await expect(page.locator('tbody tr.fi-ta-row')).toHaveCount(1);
    await expect(page.locator('tbody tr.fi-ta-row').first()).toContainText(
        'OPD',
    );

    // Reset filters: the list as on a first visit, on the tab it is on.
    await page
        .getByRole('button', { name: 'Reset filters', exact: true })
        .click();
    await expect(page.getByText('Bill type: OPD consultation')).toBeHidden();
    await expect(search(page)).toHaveValue('');
    await expect(
        page
            .locator('[data-test=patients-summary]')
            .getByRole('heading', { name: 'Today', exact: true }),
    ).toBeVisible();
    await page.getByRole('tab', { name: 'Patients' }).click();
});

for (const viewport of viewports) {
    for (const mode of ['light', 'dark'] as const) {
        test(`the Visits tab and the quick view fit at ${viewport.width}×${viewport.height}, ${mode}`, async ({
            page,
            browserName,
        }) => {
            await page.setViewportSize(viewport);
            await theme(page, mode);
            await openPatients(page, 'visits');

            const table = page.locator('.fi-ta-ctn');
            const rows = table.locator('tbody tr.fi-ta-row');
            await expect(rows.first()).toBeVisible();
            await expect(table.locator('thead')).toContainText('Amount');
            await expect(table.locator('thead')).toContainText('Given');
            await expect(
                table.locator('[data-test=visit-given]').first(),
            ).toContainText('Panadol');
            await page.waitForLoadState('networkidle');
            await expectInside(table, `visits ${viewport.width} ${mode}`, {
                window: 'width',
            });

            if (browserName === 'chromium') {
                mkdirSync(screenshots, { recursive: true });
                await page.screenshot({
                    path: `${screenshots}/patients-visits-${mode}-${viewport.width}.png`,
                    fullPage: true,
                });
            }

            await rows.first().locator('td').first().click();
            const panel = quickView(page);
            await expect(
                panel.locator('[data-test=quick-view-bill]').first(),
            ).toBeVisible();
            await expectInside(panel, `quick view ${viewport.width} ${mode}`);

            if (browserName === 'chromium') {
                await page.screenshot({
                    path: `${screenshots}/patients-quick-view-${mode}-${viewport.width}.png`,
                });
            }

            await panel.getByRole('button', { name: 'Close' }).last().click();
            await expect(panel).toBeHidden();
            await page.getByRole('tab', { name: 'Patients' }).click();
        });
    }
}
