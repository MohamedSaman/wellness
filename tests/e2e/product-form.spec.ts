import { mkdirSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';

/**
 * The admin product form in the demo clinic. Feature tests can't see any of
 * this: what the browser decides by itself, and how the form lays out.
 *
 * "E2E variants" is the one clinic category with a variant axis
 * (E2eVariantAxisSeeder); without an axis a product has a single variant.
 */

const row = (page: Page, index: number): Locator =>
    page.locator('.fi-fo-repeater-item').nth(index);

const field = (scope: Locator, name: string): Locator =>
    scope.locator(`[id$=".${name}"]`).first();

async function chooseCategory(page: Page, name: string): Promise<void> {
    const wrapper = page
        .locator('[data-field-wrapper]')
        .filter({ has: page.locator('label', { hasText: /^\s*Category/ }) })
        .first();

    await wrapper.locator('.fi-select-input-btn').click();
    await wrapper.getByRole('option', { name }).click();
    // The Add button appears once the server knows the category has an axis.
    await expect(
        page.getByRole('button', { name: 'Add variant' }),
    ).toBeVisible();
}

const screenshots = 'storage/app/screenshots/product-form';

const viewports = [
    { width: 1920, height: 1080 },
    { width: 1366, height: 768 },
    { width: 820, height: 1180 },
    { width: 390, height: 844 },
];

test.describe('layout', () => {
    for (const viewport of viewports) {
        test(`create page at ${viewport.width}px`, async ({
            page,
            browserName,
        }) => {
            await page.setViewportSize(viewport);
            await page.goto('/admin/products/create');
            await expect(
                page.locator('.fi-page-header-main-ctn, .fi-header').first(),
            ).toBeVisible();
            await field(row(page, 0), 'opening_stock').fill('10');

            // The More section open, with a pack and the location rules on show.
            const more = row(page, 0).locator('section.fi-section').first();
            await more.locator('.fi-section-header').first().click();
            await page.getByRole('button', { name: '+ Add pack' }).click();
            await expect(page.locator('[id$=".factor"]').first()).toBeVisible();

            if (browserName === 'chromium') {
                mkdirSync(screenshots, { recursive: true });
                await page.screenshot({
                    path: `${screenshots}/create-${viewport.width}.png`,
                    fullPage: true,
                });
            }

            const sideways = await page.evaluate(
                () =>
                    document.documentElement.scrollWidth -
                    document.documentElement.clientWidth,
            );
            expect(sideways).toBeLessThanOrEqual(1);
        });
    }
});

test.describe('a variant row', () => {
    const tops = async (page: Page, names: string[]): Promise<number[]> => {
        const values: number[] = [];

        for (const name of names) {
            const box = await field(row(page, 0), name).boundingBox();
            values.push(Math.round(box!.y));
        }

        return values;
    };

    const main = ['sku', 'barcode', 'price', 'cost', 'opening_stock'];

    test.beforeEach(async ({ page }) => {
        await page.goto('/admin/products/create');
        await field(row(page, 0), 'opening_stock').fill('10');
    });

    test('is one line on a wide screen', async ({ page }) => {
        await page.setViewportSize({ width: 1920, height: 1080 });

        const lines = await tops(page, [
            ...main,
            'opening_batch_no',
            'opening_expiry',
        ]);
        expect(new Set(lines).size).toBe(1);
    });

    test('is two lines on a laptop, with every label on one line', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1366, height: 768 });

        const lines = await tops(page, [
            ...main,
            'opening_batch_no',
            'opening_expiry',
        ]);
        expect(new Set(lines).size).toBe(2);

        const tallest = await row(page, 0)
            .locator('.fi-fo-field-label-ctn')
            .evaluateAll((labels) =>
                Math.max(
                    ...labels
                        .filter((label) => label.getClientRects().length > 0)
                        .map((label) => label.getBoundingClientRect().height),
                ),
            );
        expect(tallest).toBeLessThan(32);
    });

    test('stacks into a card on a phone', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });

        const lines = await tops(page, main);
        expect(new Set(lines).size).toBe(main.length);
    });
});

test('what is set under More reaches the database with More collapsed', async ({
    page,
}) => {
    await page.goto('/admin/products/create');
    const first = row(page, 0);
    const more = first.locator('section.fi-section').first();
    const name = `Under more ${Date.now()}`;

    await page.locator('[id="form.drug.generic_names"]').fill('Paracetamol');
    await page.locator('[id="form.drug.generic_names"]').press('Enter');

    for (const [id, option] of [
        ['form.drug.dosage_form', 'Tablet'],
        ['form.drug.schedule', 'Over the counter'],
    ] as const) {
        await page
            .locator(`select[id="${id}"]`)
            .selectOption({ label: option });
    }

    await page.locator('[id="form.name"]').fill(name);
    await field(first, 'price').fill('20');
    await more.locator('.fi-section-header').first().click();
    await field(first, 'bin_location').fill('A3');
    await field(first, 'reorder_level').fill('4');
    await more.locator('.fi-section-header').first().click();
    await expect(more.locator('.fi-section-header').first()).toContainText(
        'Reorder 4',
    );
    await expect(field(first, 'bin_location')).toBeHidden();

    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page).toHaveURL(/\/edit$/);

    await expect(page.getByText('More · Reorder 4 · Shelf A3')).toBeVisible();
});

test('the edit page lays out at every width', async ({ page, browserName }) => {
    await page.goto('/admin/products');
    await page.locator('tbody .fi-ta-row .fi-ta-cell a').first().click();
    await expect(page).toHaveURL(/\/edit$/);

    for (const viewport of viewports) {
        await page.setViewportSize(viewport);
        await expect(page.locator('[id="form.name"]')).toBeVisible();

        if (browserName === 'chromium') {
            mkdirSync(screenshots, { recursive: true });
            await page.screenshot({
                path: `${screenshots}/edit-${viewport.width}.png`,
                fullPage: true,
            });
        }

        const sideways = await page.evaluate(
            () =>
                document.documentElement.scrollWidth -
                document.documentElement.clientWidth,
        );
        expect(sideways).toBeLessThanOrEqual(1);
    }
});

test('help text is one icon away, with its wording unchanged', async ({
    page,
}) => {
    await page.goto('/admin/products/create');

    // A text field, and a toggle (its label sits beside the switch).
    for (const [id, text] of [
        ['form.drug.brand_name', null],
        ['form.name', 'As it should appear on the receipt'],
        ['form.whole_units_only', 'Sold, received, counted and returned'],
    ] as const) {
        const wrapper = page
            .locator('[data-field-wrapper]')
            .filter({ has: page.locator(`[id="${id}"]`) })
            .first();

        if (text === null) {
            await expect(wrapper.locator('.fi-sc-icon')).toHaveCount(0);
            continue;
        }

        await expect(page.getByText(text)).toBeHidden();
        await wrapper.locator('.fi-sc-icon').first().hover();
        await expect(page.getByText(text)).toBeVisible();
        await page.mouse.move(0, 0);
    }
});

test('a collapsed section sums up what was just typed', async ({ page }) => {
    await page.goto('/admin/products/create');

    const medicine = page
        .locator('section.fi-section')
        .filter({ hasText: 'Generic and brand names' })
        .first();

    await page.getByLabel('Brand name').fill('Zzzbrand');
    await medicine.locator('.fi-section-header').first().click();

    await expect(medicine.locator('.fi-section-header')).toContainText(
        'Zzzbrand',
    );
    await expect(page.getByLabel('Brand name')).toBeHidden();
});

test.describe('opening stock fields', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/admin/products/create');
        await chooseCategory(page, 'E2E variants');
        await page.getByRole('button', { name: 'Add variant' }).click();
        await expect(page.locator('.fi-fo-repeater-item')).toHaveCount(2);
    });

    test("follow each row's own opening stock, with no trip to the server", async ({
        page,
    }) => {
        const first = row(page, 0);
        const second = row(page, 1);

        for (const scope of [first, second]) {
            await expect(field(scope, 'opening_batch_no')).toBeHidden();
            await expect(field(scope, 'opening_expiry')).toBeHidden();
        }

        let serverCalls = 0;
        page.on('request', (request) => {
            if (request.url().includes('/livewire')) {
                serverCalls++;
            }
        });

        await field(first, 'opening_stock').fill('10');

        await expect(field(first, 'opening_batch_no')).toBeVisible();
        await expect(field(first, 'opening_expiry')).toBeVisible();
        await expect(field(second, 'opening_batch_no')).toBeHidden();
        await expect(field(second, 'opening_expiry')).toBeHidden();

        await field(second, 'opening_stock').fill('5');
        await expect(field(second, 'opening_batch_no')).toBeVisible();

        await field(first, 'opening_stock').fill('0');
        await expect(field(first, 'opening_batch_no')).toBeHidden();
        await expect(field(second, 'opening_batch_no')).toBeVisible();

        expect(serverCalls).toBe(0);
    });

    test('Tab goes from opening stock straight to the batch', async ({
        page,
    }) => {
        const first = row(page, 0);

        await field(first, 'opening_stock').fill('10');
        await field(first, 'opening_stock').press('Tab');

        await expect(field(first, 'opening_batch_no')).toBeFocused();
    });

    test('keep what was typed while they are hidden', async ({ page }) => {
        const first = row(page, 0);

        await field(first, 'opening_stock').fill('10');
        await field(first, 'opening_batch_no').fill('B12');
        await field(first, 'opening_stock').fill('0');
        await expect(field(first, 'opening_batch_no')).toBeHidden();

        await field(first, 'opening_stock').fill('3');
        await expect(field(first, 'opening_batch_no')).toHaveValue('B12');
    });
});

test.describe('a failed save', () => {
    const footer = (page: Page): Locator =>
        page.getByRole('button', { name: /Jump to first error/ });

    test('reports on the fields, with no bubble from the browser', async ({
        page,
    }) => {
        await page.goto('/admin/products/create');
        await page.getByRole('button', { name: 'Save', exact: true }).click();

        // Generic name, dosage form, schedule, name and price.
        await expect(footer(page)).toContainText('5 errors');
        await expect(page.locator('[data-validation-error]')).toHaveCount(5);
    });

    test('opens every section and row with an error, and the footer finds them', async ({
        page,
    }) => {
        await page.goto('/admin/products');
        await page.locator('tbody .fi-ta-row .fi-ta-cell a').first().click();
        await expect(page).toHaveURL(/\/edit$/);

        // Everything but the Product block starts collapsed on edit.
        await expect(page.locator('[id="form.drug.brand_name"]')).toBeHidden();
        const reorder = field(row(page, 0), 'reorder_level');
        await expect(reorder).toBeHidden();

        // Two errors in two collapsed places, set directly: their fields can't be reached.
        await page.locator('[id="form.name"]').fill('');
        await page.evaluate(() => {
            const input = document.querySelector<HTMLInputElement>(
                '[id$=".reorder_level"]',
            );
            input!.value = '-1';
            input!.dispatchEvent(new Event('input', { bubbles: true }));
            const brand = document.querySelector<HTMLInputElement>(
                '[id="form.drug.storage_note"]',
            );
            brand!.value = 'x'.repeat(300);
            brand!.dispatchEvent(new Event('input', { bubbles: true }));
        });

        await page.getByRole('button', { name: 'Save', exact: true }).click();

        await expect(footer(page)).toContainText('3 errors');
        await expect(reorder).toBeVisible();
        await expect(
            page.locator('[id="form.drug.storage_note"]'),
        ).toBeVisible();

        // Scroll away, then back to the first error, clear of the top bar.
        await page.evaluate(() =>
            window.scrollTo(0, document.body.scrollHeight),
        );
        await footer(page).click();

        const first = page.locator('[data-validation-error]').first();
        await expect(first).toBeInViewport();
        await page.waitForTimeout(800);

        const covered = await first.evaluate((el) => {
            const bar = document.querySelector('.fi-topbar-ctn, .fi-topbar');
            const wrapper = el.closest('[data-field-wrapper]')!;

            return (
                (bar?.getBoundingClientRect().bottom ?? 0) >
                wrapper.getBoundingClientRect().top
            );
        });
        expect(covered).toBe(false);
    });

    test('keeps the footer in view on a long form', async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 768 });
        await page.goto('/admin/products/create');

        const save = page.getByRole('button', { name: 'Save', exact: true });
        await expect(save).toBeInViewport();
        await expect(
            page.getByRole('button', { name: 'Save & add another' }),
        ).toBeInViewport();
    });
});

test('a product is duplicated from its row in the list, and saved as new', async ({
    page,
}) => {
    await page.goto('/admin/products');
    const row = page.locator('tbody .fi-ta-row').first();
    const original = await row.getByRole('link').first().innerText();

    await row.locator('.fi-dropdown-trigger button').click();
    await page
        .locator('.fi-dropdown-list-item:visible', { hasText: 'Duplicate' })
        .click();
    await expect(page).toHaveURL(/\/admin\/products\/create\?duplicate=/);

    const name = page.locator('[id="form.name"]');
    await expect(name).toHaveValue('');
    await expect(page.locator('[id$=".sku"]').first()).toHaveValue('');
    await expect(page.locator('[id$=".barcode"]').first()).toHaveValue('');
    await expect(page.locator('[id$=".opening_stock"]').first()).toHaveValue(
        '0',
    );
    await expect(page.getByText('Copied from')).toBeVisible();
    await expect(
        page.getByText('Check name and strength. Name prints on the receipt.'),
    ).toBeVisible();

    const copy = `Copy ${Date.now()}`;
    await name.fill(copy);
    await page.locator('[id="form.drug.strength_value"]').fill('987');
    await page.locator('[id="form.drug.strength_unit"]').fill('mg');
    await page.getByRole('button', { name: 'Save', exact: true }).click();

    await expect(page).toHaveURL(/\/edit$/);
    await expect(page.locator('[id="form.name"]')).toHaveValue(copy);
    expect(original).toBeTruthy();
});
