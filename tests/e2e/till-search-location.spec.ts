import { expect, test } from '@playwright/test';
import { dialog, expectInside, openPayment, useTill } from './helpers';

/**
 * Till search by location (docs/PHARMACY.md, "Till search by location"),
 * on the demo clinic: Amoxil is held at General Pharmacy only, Panadol at
 * both counters. The OPD's till (T2, Doctor's Medicines) lists its own
 * stock, looks at General Pharmacy's with Alt+L, can't add what it doesn't
 * hold, asks for it with the Request dialog, and says where a scanned item
 * is. Every new sale starts on the till's own location again.
 */

test.use({ viewport: { width: 1280, height: 800 } });

test('the OPD till sells its own stock and asks General Pharmacy for the rest', async ({
    page,
}) => {
    const search = page.locator('[data-test=pos-search]');
    const scope = page.locator('[data-test=search-scope]');
    const chip = page.locator('[data-test=search-scope-chip]');
    const results = page.locator('[data-test=search-result]');
    const notice = page.locator('[data-test=search-notice]');
    const lines = page.locator('[data-test=cart-line]');
    const section = page.getByRole('region', { name: 'Find items' });

    await page.goto('/pos');
    await useTill(page, 'T2');
    const linesBefore = await lines.count();

    await test.step('This location by default; Quarantine is never offered', async () => {
        await expect(chip).toContainText("Selling from Doctor's Medicines");
        await expect(scope.locator('option')).toHaveText([
            "This location (Doctor's Medicines)",
            'General Pharmacy',
            'All locations',
        ]);

        await search.fill('Amoxil');
        await expect(
            page.getByText("Nothing in Doctor's Medicines stock matches."),
        ).toBeVisible();
        await expect(results).toHaveCount(0);

        // Held here: listed with this location's stock only.
        await search.fill('Panadol');
        await expect(results).toHaveCount(1);
        await expect(
            results.first().locator('[data-test=result-locations]'),
        ).toHaveCount(0);
    });

    let sku = '';

    await test.step('Alt+L: General Pharmacy, view only, with a Request button', async () => {
        await search.fill('Amoxil');
        await page.keyboard.press('Alt+KeyL');
        await expect(scope).toHaveValue(/\d+/);
        await expect(chip).toContainText('Looking at General Pharmacy');

        const row = page.locator('[data-test=search-results] li', {
            hasText: 'Amoxil',
        });
        await expect(row.locator('[data-test=result-locations]')).toHaveText(
            /GEN \d+ · DOC 0/,
        );
        await expect(row.locator('[data-test=result-view-only]')).toContainText(
            "Not in Doctor's Medicines stock",
        );
        await expectInside(section, 'search looking at General Pharmacy', {
            window: 'width',
        });
        sku = (
            (await row.locator('[data-test=result-codes]').textContent()) ?? ''
        )
            .trim()
            .split(' ')[0];

        // Picking it (arrow down, Enter) adds nothing and says why.
        await search.press('ArrowDown');
        await search.press('Enter');
        await expect(notice).toContainText(
            /Not in Doctor's Medicines stock · General Pharmacy has \d+/,
        );
        await expect(lines).toHaveCount(linesBefore);

        // Panadol is held here too: it still adds from this view.
        await search.fill('Panadol');
        await expect(
            results.first().locator('[data-test=result-locations]'),
        ).toHaveText(/GEN \d+ · DOC \d+/);
        await results.first().click();
        await expect(lines).toHaveCount(linesBefore + 1);
    });

    await test.step("Request to Doctor's Medicines", async () => {
        await search.fill('Amoxil');
        await page
            .locator('[data-test=search-results] li', { hasText: 'Amoxil' })
            .locator('[data-test=result-request]')
            .click();

        const request = dialog(page, "Request to Doctor's Medicines");
        await expect(request).toBeVisible();
        await expect(
            request.locator('[data-test=stock-request-from]'),
        ).toContainText('General Pharmacy');
        await expectInside(request, 'Request dialog');

        await request.locator('[data-test=stock-request-quantity]').fill('2');
        await request.locator('[data-test=stock-request-send]').click();
        await expect(request).toBeHidden();
        await expect(page.locator('[data-test=search-info]')).toContainText(
            /Requested RQ-\d+ from General Pharmacy/,
        );
    });

    await test.step('A scan of an item not held here adds nothing and says where it is', async () => {
        await page.locator('[data-test=search-scope-reset]').click();
        await expect(chip).toContainText("Selling from Doctor's Medicines");

        await search.fill(sku);
        await search.press('Enter');
        await expect(notice).toContainText(
            /Not in Doctor's Medicines stock · General Pharmacy has \d+/,
        );
        await expect(
            notice.locator('[data-test=search-notice-request]'),
        ).toHaveText('Request transfer');
        await expect(lines).toHaveCount(linesBefore + 1);

        await notice.locator('[data-test=search-notice-request]').click();
        const request = dialog(page, "Request to Doctor's Medicines");
        await expect(request).toBeVisible();
        await request.getByRole('button', { name: 'Cancel' }).click();
        await expect(request).toBeHidden();
    });

    await page.getByRole('button', { name: 'Clear cart' }).click();
});

test("every new sale searches the till's own location again", async ({
    page,
}) => {
    const chip = page.locator('[data-test=search-scope-chip]');

    await page.goto('/pos');
    await useTill(page, 'T1');

    // Looking at the OPD's stock, Panadol (held here too) is sold.
    await page.keyboard.press('Alt+KeyL');
    await expect(chip).toContainText("Looking at Doctor's Medicines");
    await page.locator('[data-test=pos-search]').fill('Panadol');
    await page.locator('[data-test=search-result]').first().click();
    await expect(page.locator('[data-test=cart-line]')).toHaveCount(1);

    const pay = await openPayment(page);
    await pay.getByRole('button', { name: 'Exact' }).click();
    await pay.locator('[data-test=complete-sale]').click();
    const done = page.locator('[data-test=sale-done]');
    await done.locator('[data-test=new-sale]').click();
    await expect(done).toBeHidden();

    await expect(chip).toContainText('Selling from General Pharmacy');
    await expect(page.locator('[data-test=search-scope]')).toHaveValue('here');
});
