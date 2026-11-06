import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';

/**
 * "Create new item" on purchase order and goods received lines, in the demo
 * clinic. A scanner types the code and presses Enter: in a Barcode field that
 * must move on, not create a half-entered item. (The retail form has the same
 * field; the clinic can't reach it, so RetailPurchasingTest covers that one.)
 */

const pages = [
    { name: 'purchase order', path: 'purchase-orders' },
    { name: 'goods received', path: 'goods-receipts' },
];

const field = (modal: Locator, name: string): Locator =>
    modal.locator(`[id="mountedActionSchema0.${name}"]`);

// Names and barcodes no other test or browser has: they share a database.
const unique = (): string => `${crypto.randomUUID().slice(0, 13)} item`;
const barcode = (): string =>
    `${Date.now()}${Math.floor(Math.random() * 1000)}`.padEnd(16, '0');

async function openNewItem(page: Page, path: string): Promise<Locator> {
    await page.goto(`/admin/${path}/create`);
    await page.getByRole('button', { name: 'Create new item' }).first().click();

    const modal = page
        .locator('.fi-modal-window:visible')
        .filter({ hasText: 'New item' });
    await expect(modal).toBeVisible();

    return modal;
}

/**
 * Everything required, so a submit would create the item. And a price: these
 * items stay in the shared database, and admin-tables.spec.ts expects a price
 * on every row of the products list.
 */
async function fillIn(modal: Locator, name: string): Promise<void> {
    await field(modal, 'name').fill(name);
    await field(modal, 'price').fill('20');
    await field(modal, 'generic_names').fill('Paracetamol');
    await field(modal, 'generic_names').press('Enter');
    await expect(
        modal.locator('.fi-badge', { hasText: 'Paracetamol' }),
    ).toBeVisible();
    await modal
        .locator('select[id$=".dosage_form"]')
        .selectOption({ label: 'Tablet' });
    // The dosage form fills in the base unit and packs from the server.
    await expect(field(modal, 'unit')).toHaveValue('Tablet');
    await modal
        .locator('select[id$=".schedule"]')
        .selectOption({ label: 'Over the counter' });
}

async function scan(input: Locator, code: string): Promise<void> {
    await input.focus();
    await input.page().keyboard.type(code);
    await input.page().keyboard.press('Enter');
}

/** Every time the modal's form reaches the server. */
function submits(page: Page): string[] {
    const seen: string[] = [];

    page.on('request', (request) => {
        const body = request.postData() ?? '';

        if (/"method":"callMountedAction"/.test(body.replaceAll('\\', ''))) {
            seen.push(request.url());
        }
    });

    return seen;
}

for (const { name: title, path } of pages) {
    test.describe(`Enter in a Barcode of a new item, ${title}`, () => {
        test('the item barcode moves on and creates nothing', async ({
            page,
        }) => {
            const modal = await openNewItem(page, path);
            await fillIn(modal, unique());
            const seen = submits(page);
            const code = barcode();

            await scan(field(modal, 'barcode'), code);

            await expect(field(modal, 'generic_names')).toBeFocused();
            await expect(field(modal, 'barcode')).toHaveValue(code);
            await page.waitForTimeout(500);
            expect(seen).toEqual([]);
            await expect(modal).toBeVisible();
        });

        test('a pack barcode moves on and creates nothing', async ({
            page,
        }) => {
            const modal = await openNewItem(page, path);
            await fillIn(modal, unique());
            const seen = submits(page);
            const code = barcode();
            const pack = modal
                .locator('[id*=".packs."][id$=".barcode"]')
                .first();

            await scan(pack, code);

            await expect(
                modal.locator('[id*=".packs."][id$=".price"]').first(),
            ).toBeFocused();
            await expect(pack).toHaveValue(code);
            await page.waitForTimeout(500);
            expect(seen).toEqual([]);
            await expect(modal).toBeVisible();
        });

        test('with required fields empty, it stays on its way down the form', async ({
            page,
        }) => {
            const modal = await openNewItem(page, path);
            const seen = submits(page);

            await scan(field(modal, 'barcode'), barcode());

            // Before, the browser's required check threw the cursor back to Item name.
            await expect(field(modal, 'generic_names')).toBeFocused();
            await page.waitForTimeout(500);
            expect(seen).toEqual([]);
            await expect(modal).toBeVisible();
        });

        test('something that is not a medicine moves on to Manufacturer', async ({
            page,
        }) => {
            const modal = await openNewItem(page, path);

            await field(modal, 'is_medicine').click();
            await expect(field(modal, 'generic_names')).toBeHidden();

            await scan(field(modal, 'barcode'), barcode());

            await expect(field(modal, 'brand_id')).toBeFocused();
            await expect(modal).toBeVisible();
        });

        test('a scanned barcode is saved with the item, on its line', async ({
            page,
        }) => {
            const modal = await openNewItem(page, path);
            const name = unique();
            const code = barcode();

            await fillIn(modal, name);
            await scan(field(modal, 'barcode'), code);
            await expect(field(modal, 'generic_names')).toBeFocused();

            await modal
                .getByRole('button', { name: 'Create and use on this line' })
                .click();

            await expect(modal).toBeHidden();
            await expect(page.getByText(`${name} created`)).toBeVisible();
            await expect(
                page.locator('.fi-select-input-btn', { hasText: name }).first(),
            ).toBeVisible();

            await page.goto(`/admin/products?search=${code}`);
            await expect(
                page.locator('tbody .fi-ta-row', { hasText: name }),
            ).toBeVisible();
        });

        test('Enter in another field still submits', async ({ page }) => {
            const modal = await openNewItem(page, path);
            const name = unique();

            await fillIn(modal, name);
            await field(modal, 'name').press('Enter');

            await expect(page.getByText(`${name} created`)).toBeVisible();
        });
    });
}
