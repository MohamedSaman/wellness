import { mkdirSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { expectInside } from './helpers';

/**
 * The admin tables whose columns were fitted to the page (Payables, a
 * supplier's Invoices, Products, Goods received, Purchase orders) at laptop
 * and desktop widths, light and dark, in the demo clinic: nothing scrolls
 * sideways, the row actions cell never covers a data column, and the column
 * that matters most (Outstanding on Payables) is fully in view. On Payables
 * the aging cards' amounts fit their cards too. In Chromium it also saves
 * screenshots for review to storage/app/screenshots/tables (not committed).
 */

const viewports = [
    { width: 1280, height: 800 },
    { width: 1440, height: 900 },
    { width: 1920, height: 1080 },
];

const screenshots = 'storage/app/screenshots/tables';

/** A record's row (not the summary under the table). */
const recordRow =
    'tbody > tr.fi-ta-row:not(.fi-ta-summary-row, .fi-ta-summary-header-row)';

type Table = {
    name: string;
    open: (page: Page) => Promise<void>;
    /** The header of the column that must always be fully in view. */
    key: string;
    /** Does every row have an actions cell? */
    actions: boolean;
};

const tables: Table[] = [
    {
        name: 'payables',
        open: async (page) => {
            await page.goto('/admin/supplier-payables');
        },
        key: 'Outstanding',
        actions: true,
    },
    {
        name: 'supplier-invoices',
        open: async (page) => {
            await page.goto('/admin/suppliers');
            await page.getByText('Hemas Pharmaceuticals').first().click();
            await expect(page).toHaveURL(/\/admin\/suppliers\/[0-9a-f-]+$/);
            // The Invoices tab (the first) loads once its panel scrolls into view.
            await expect(
                page.getByRole('tab', { name: 'Invoices' }),
            ).toHaveAttribute('aria-selected', 'true');
            await page.getByRole('tabpanel').scrollIntoViewIfNeeded();
        },
        key: 'Outstanding',
        actions: true,
    },
    {
        name: 'products',
        open: async (page) => {
            await page.goto('/admin/products');
        },
        key: 'Price',
        actions: true,
    },
    {
        name: 'goods-received',
        open: async (page) => {
            await page.goto('/admin/goods-receipts');
        },
        key: 'Total',
        actions: false,
    },
    {
        name: 'purchase-orders',
        open: async (page) => {
            await page.goto('/admin/purchase-orders');
        },
        key: 'Expected total',
        actions: false,
    },
];

/**
 * Runs in the page: in each row, the key column's text and the last data
 * cell must be what the browser shows at their centres (nothing laid over
 * them), and the last data cell must end where the actions cell starts.
 */
function findCovered(
    table: Element,
    args: { key: string; actions: boolean; rowSelector: string },
): string[] {
    const problems: string[] = [];
    const headers = Array.from(
        table.querySelectorAll('thead tr:last-child > th'),
    );
    const keyIndex = headers.findIndex(
        (th) => (th.textContent ?? '').trim() === args.key,
    );

    if (keyIndex === -1) {
        return [`no "${args.key}" column`];
    }

    const shows = (el: Element, what: string, row: number): void => {
        const rect = el.getBoundingClientRect();
        const x = rect.left + rect.width / 2;
        const y = rect.top + rect.height / 2;
        const top = document.elementFromPoint(x, y);

        if (top === null || !el.contains(top)) {
            problems.push(
                `row ${row}: ${what} is covered at (${Math.round(x)}, ${Math.round(y)}) by <${top?.tagName.toLowerCase()} class="${top?.className}">`,
            );
        }
    };

    const rows = Array.from(table.querySelectorAll(args.rowSelector));

    rows.forEach((row, index) => {
        row.scrollIntoView({ block: 'center' });
        const cells = Array.from(row.children);
        const key = cells[keyIndex];
        const text = key?.querySelector('.fi-ta-text-item, .fi-badge');

        if (text === null || text === undefined) {
            problems.push(`row ${index}: no "${args.key}" text`);
        } else {
            shows(text, `"${args.key}" (${text.textContent?.trim()})`, index);
        }

        if (!args.actions) {
            return;
        }

        const actions = row.querySelector(':scope > td:has(> .fi-ta-actions)');
        const last = actions?.previousElementSibling;

        if (!actions || !last) {
            problems.push(`row ${index}: no actions cell`);

            return;
        }

        const lastRight = last.getBoundingClientRect().right;
        const actionsLeft = actions.getBoundingClientRect().left;

        if (lastRight > actionsLeft + 1) {
            problems.push(
                `row ${index}: the last data cell ends at ${Math.round(lastRight)}, under the actions cell from ${Math.round(actionsLeft)}`,
            );
        }

        shows(last, 'the last data cell', index);
    });

    window.scrollTo(0, 0);

    return problems;
}

for (const viewport of viewports) {
    for (const mode of ['light', 'dark'] as const) {
        test.describe(`${viewport.width}×${viewport.height}, ${mode}`, () => {
            test.use({ viewport });

            test.beforeEach(async ({ page }) => {
                await page.addInitScript((theme) => {
                    window.localStorage.setItem('theme', theme);
                }, mode);
            });

            for (const table of tables) {
                test(`${table.name}: fits, and the actions cover nothing`, async ({
                    page,
                    browserName,
                }) => {
                    await table.open(page);
                    await expect(page.locator('html')).toHaveAttribute(
                        'data-pos-theme',
                        'clinic_teal',
                    );

                    if (mode === 'dark') {
                        await expect(page.locator('html')).toHaveClass(
                            /\bdark\b/,
                        );
                    }

                    const ctn: Locator = page.locator('.fi-ta-ctn').filter({
                        has: page.locator('thead th', {
                            hasText: table.key,
                        }),
                    });
                    await expect(ctn.locator(recordRow).first()).toBeVisible();
                    await page.waitForLoadState('networkidle');

                    expect(
                        await ctn
                            .locator('.fi-ta-table')
                            .evaluate(findCovered, {
                                key: table.key,
                                actions: table.actions,
                                rowSelector: recordRow,
                            }),
                        `${table.name} ${viewport.width} ${mode}`,
                    ).toEqual([]);

                    // Nothing in the page sticks out sideways or scrolls sideways (the table included).
                    await expectInside(
                        page.locator('.fi-main'),
                        `${table.name} ${viewport.width} ${mode}`,
                        { window: 'width' },
                    );

                    if (table.name === 'payables') {
                        const cards = page.locator(
                            '.fi-wi-stats-overview-stat',
                        );
                        await expect(cards).toHaveCount(6);

                        for (const card of await cards.all()) {
                            await expectInside(
                                card,
                                `aging card ${viewport.width} ${mode}`,
                                { window: 'width' },
                            );
                        }

                        // The row action is an icon that still has its name (for screen readers and the tooltip).
                        await expect(
                            ctn
                                .getByRole('button', {
                                    name: 'Change due date',
                                })
                                .first(),
                        ).toBeVisible();
                    }

                    if (table.name === 'supplier-invoices') {
                        // Two actions on a row: a ⋮ menu that opens into the page.
                        await ctn
                            .locator(recordRow)
                            .first()
                            .getByRole('button', { name: 'Actions' })
                            .click();
                        const menu = page.getByRole('menu', {
                            name: 'Actions',
                        });
                        await expect(
                            menu.getByRole('menuitem', {
                                name: 'Change due date',
                            }),
                        ).toBeVisible();
                        await expectInside(
                            menu,
                            `actions menu ${viewport.width} ${mode}`,
                        );
                        await page.keyboard.press('Escape');
                    }

                    if (browserName === 'chromium') {
                        mkdirSync(screenshots, { recursive: true });
                        await page.screenshot({
                            path: `${screenshots}/${table.name}-${viewport.width}-${mode}.png`,
                            fullPage: true,
                        });
                    }
                });
            }
        });
    }
}
