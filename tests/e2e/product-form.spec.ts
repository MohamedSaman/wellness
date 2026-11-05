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
            await expect(
                page.locator('[id$=".factor"]').first(),
            ).toBeVisible();

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

    test('follow each row\'s own opening stock, with no trip to the server', async ({
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
