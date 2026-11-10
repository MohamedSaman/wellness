import { expect, test } from '@playwright/test';
import { addItem, dialog, expectInside, useTill } from './helpers';

/**
 * Till layout smoke test: every till dialog and panel at laptop and desktop
 * widths, checked for content that sticks out past its edges (or makes it
 * scroll sideways). Walks one sale through the demo clinic: an OPD bill on
 * T2, then on T1 a price typed at the till (asked for, outside its limits,
 * then confirmed), a batch pick, discount, prescription, payment, the sale
 * done, recent sales, closing the shift and its printed summary, and back on
 * T2 a stock request.
 */

const viewports = [
    { width: 1280, height: 800 },
    { width: 1440, height: 900 },
    { width: 1920, height: 1080 },
];

for (const viewport of viewports) {
    test.describe(`${viewport.width}×${viewport.height}`, () => {
        test.use({ viewport });

        test('every till dialog and panel fits inside its box', async ({
            page,
            context,
        }) => {
            const cart = page.getByRole('region', { name: 'Cart' });

            await page.goto('/pos');

            await test.step('OPD bill: Medicines amount (T2)', async () => {
                await useTill(page, 'T2');
                // The consultation is on the bill already; a Medicines amount needs a medicine too.
                await addItem(page, 'Panadol');
                await page
                    .locator('[data-test=medicines-amount]')
                    .fill('1250.00');
                await expect(
                    page.locator('[data-test=opd-medicines]'),
                ).toContainText('1,250.00');
                await expectInside(cart, 'cart with an OPD bill', {
                    window: 'width',
                });
                // The medicines' list value (one tablet, 15.00); below it, a warning that never blocks.
                await expect(
                    page.locator('[data-test=medicines-list-value]'),
                ).toContainText('15.00');
                await expect(
                    page.locator('[data-test=medicines-below-list]'),
                ).toHaveCount(0);
                await page
                    .locator('[data-test=medicines-amount]')
                    .fill('10.00');
                await expect(
                    page.locator('[data-test=medicines-below-list]'),
                ).toBeVisible();
                await expect(
                    page.locator('[data-test=pay-button]'),
                ).toBeEnabled();
                await expectInside(cart, 'cart with an OPD bill below list', {
                    window: 'width',
                });
                await page.getByRole('button', { name: 'Clear cart' }).click();
                await page.getByRole('button', { name: 'Change till' }).click();
            });

            await useTill(page, 'T1');

            await test.step('Price changed at the till (T1)', async () => {
                const prices = page.locator('[data-test=line-price]');
                const note = page.locator('[data-test=line-price-note]');

                // A lab test asks for its price: its box opens as it is added.
                await addItem(page, 'CBS');
                await expect(prices.last()).toBeFocused();
                await expect(note).toContainText('up to 3,000.00');
                await expectInside(cart, 'cart waiting for a price', {
                    window: 'width',
                });

                // Payment waits for it.
                await page.keyboard.press('F9');
                await expect(
                    page.locator('[data-test=payment-total]'),
                ).toHaveCount(0);

                // Above its maximum: said under the line, and not taken.
                await page.keyboard.type('3500');
                await page.keyboard.press('Enter');
                await expect(note).toContainText(
                    "can't be more than LKR 3,000.00",
                );
                await expect(prices.last()).toBeFocused();
                await expectInside(
                    cart,
                    'cart with a price outside its limits',
                    {
                        window: 'width',
                    },
                );

                await prices.last().fill('850');
                await page.keyboard.press('Enter');
                await expect(note).toHaveCount(0);
                await expect(
                    page.locator('[data-test=pos-search]'),
                ).toBeFocused();
                await expect(
                    page.locator('[data-test=line-list-price]'),
                ).toContainText('1,200.00');

                // A dressing (stock, not a medicine) keeps its list price until one is typed.
                await addItem(page, 'Wound dressing');
                await expect(prices.last()).not.toBeFocused();
                await expect(prices.last()).toHaveAttribute(
                    'placeholder',
                    '500.00',
                );
                await expectInside(cart, 'cart with changed prices', {
                    window: 'width',
                });
                await page.getByRole('button', { name: 'Clear cart' }).click();
            });

            await test.step('Batch pick on a cart line', async () => {
                await addItem(page, 'Panadol');
                await addItem(page, 'Amoxil');
                const batch = page.locator('[data-test=line-batch]').first();
                await expect(batch).toBeVisible();
                await batch.selectOption({ index: 1 });
                await expectInside(cart, 'cart with a picked batch', {
                    window: 'width',
                });
            });

            await test.step('Discount dialog', async () => {
                await page.keyboard.press('F4');
                const discount = dialog(page, 'Discount on the whole sale');
                await expectInside(discount, 'Discount dialog');
                await page.keyboard.press('Escape');
                await expect(discount).toBeHidden();
            });

            await test.step('Patient & prescription dialog', async () => {
                await page.locator('[data-test=prescription-button]').click();
                const rx = page.locator('[data-test=prescription-dialog]');
                await expectInside(rx, 'Patient & prescription dialog');

                // Matches by name, and "New patient" for a name typed.
                await rx.locator('[data-test=patient-name]').fill('perera');
                await expect(
                    rx.locator('[data-test=patient-result]'),
                ).toHaveCount(2);
                await expect(
                    rx.locator('[data-test=patient-add]'),
                ).toBeVisible();
                await expectInside(rx, 'Patient & prescription, matches');

                // A long new name and an age: the row still fits.
                await rx
                    .locator('[data-test=patient-name]')
                    .fill(
                        'Wijesinghe Mudiyanselage Ralahamillage Nimalasiri Bandara',
                    );
                await rx.locator('[data-test=patient-age]').fill('8m');
                await page.keyboard.press('Enter');
                await expect(
                    rx.locator('[data-test=patient-chosen]'),
                ).toBeVisible();
                await expectInside(rx, 'Patient & prescription, new patient');
                await rx.locator('[data-test=patient-change]').click();

                await page.keyboard.press('Escape');
                await expect(rx).toBeHidden();
            });

            await test.step('Pay dialog', async () => {
                // F9: the patient step first; a patient found by name.
                await page.keyboard.press('F9');
                const rx = page.locator('[data-test=prescription-dialog]');
                await expect(rx).toBeVisible();
                await expect(
                    rx.locator('[data-test=patient-skip]'),
                ).toBeVisible();
                await rx.locator('[data-test=patient-name]').fill('nimal');
                await expect(
                    rx.locator('[data-test=patient-result]'),
                ).toHaveCount(1);
                await page.keyboard.press('ArrowDown');
                await page.keyboard.press('Enter');
                await expect(
                    rx.locator('[data-test=patient-chosen]'),
                ).toContainText('Nimal Perera');
                await expectInside(
                    rx,
                    'Patient & prescription, patient chosen',
                );
                await rx.locator('[data-test=prescription-continue]').click();
                await expect(
                    page.locator('[data-test=patient-chip]'),
                ).toContainText('Nimal Perera');

                const pay = dialog(page, 'Payment');
                await expect(pay).toBeVisible();
                await expectInside(pay, 'Pay dialog');

                await pay.getByRole('button', { name: 'Add card' }).click();
                await expect(
                    pay.locator('[data-test=payment-reference]'),
                ).toBeVisible();
                await expectInside(pay, 'Pay dialog, split with card');
                await pay
                    .getByRole('button', { name: 'Remove payment' })
                    .last()
                    .click();

                await pay.getByRole('button', { name: 'Exact' }).click();
                await expect(
                    pay.locator('[data-test=change-due]'),
                ).toBeVisible();
                await expect(
                    pay.locator('[data-test=complete-sale]'),
                ).toBeEnabled();
                await pay.locator('[data-test=complete-sale]').click();
            });

            await test.step('Sale complete dialog', async () => {
                const done = page.locator('[data-test=sale-done]');
                await expectInside(done, 'Sale complete dialog');
                await done.locator('[data-test=new-sale]').click();
                await expect(done).toBeHidden();
            });

            await test.step('Recent sales dialog', async () => {
                await page.keyboard.press('F6');
                const recent = page.locator('[data-test=recent-sales]');
                await expect(
                    recent.locator('[data-test=recent-sale]').first(),
                ).toBeVisible();
                await expectInside(recent, 'Recent sales dialog');
                await page.keyboard.press('Escape');
                await expect(recent).toBeHidden();
            });

            await test.step('Close shift dialog and its summary', async () => {
                await page.locator('[data-test=close-shift-button]').click();
                const close = dialog(page, /Close shift|Shift closed/);
                await expect(
                    close.locator('[data-test=shift-summary]'),
                ).toBeVisible();
                await expectInside(close, 'Close shift dialog');

                await close.locator('[data-test=counted-cash]').fill('1000.00');
                await expect(
                    close.locator('[data-test=shift-difference]'),
                ).toBeVisible();
                await expectInside(close, 'Close shift dialog, counted');

                await close.locator('[data-test=close-shift]').click();
                const print = close.locator('[data-test=print-shift-summary]');
                await expect(print).toBeVisible();
                await expectInside(close, 'Shift closed dialog');

                // The printable summary, opened without ?print=1 (no print dialog in the test).
                const href = await print.getAttribute('href');
                const summary = await context.newPage();
                await summary.goto(href!.replace(/\?print=1$/, ''));
                // The clinic prints it on the receipt printer, as a receipt.
                await expect(
                    summary.locator('[data-test=thermal-lines]'),
                ).toContainText('SHIFT SUMMARY');
                await expectInside(
                    summary.locator('[data-test=receipt]'),
                    'printed shift summary',
                    { window: 'width' },
                );
                await summary.close();

                await close.getByRole('button', { name: 'Done' }).click();
                await expect(close).toBeHidden();
            });

            await test.step('Stock requests page', async () => {
                // Only the OPD's till (T2) asks General Pharmacy for stock; T1 has no link.
                await expect(
                    page.locator('[data-test=stock-requests-link]'),
                ).toHaveCount(0);
                await useTill(page, 'T2');
                await page.locator('[data-test=stock-requests-link]').click();
                await page
                    .locator('[data-test=request-search]')
                    .fill('Panadol');
                await page
                    .locator('[data-test=request-results] button')
                    .first()
                    .click();
                await expect(
                    page.locator('[data-test=request-line]'),
                ).toHaveCount(1);
                await expectInside(page.locator('main'), 'Stock requests', {
                    window: 'width',
                });
            });
        });
    });
}
