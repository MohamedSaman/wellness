import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';

/**
 * Till layout smoke test: every till dialog and panel at laptop and desktop
 * widths, checked for content that sticks out past its edges (or makes it
 * scroll sideways). Walks one sale through the demo clinic: an OPD bill on
 * T2, then on T1 a batch pick, discount, prescription, payment, the sale
 * done, recent sales, closing the shift and its printed summary, and back on
 * T2 a stock request.
 */

const viewports = [
    { width: 1280, height: 800 },
    { width: 1440, height: 900 },
    { width: 1920, height: 1080 },
];

type Check = {
    /** Must the box fit the window's height too (dialogs), or only its width (pages)? */
    window: 'both' | 'width';
};

/**
 * Runs in the page: what sticks out of `root`. A child may only leave the
 * box upwards or downwards inside a scrolling area (a long list); nothing may
 * leave it sideways, and nothing may scroll sideways.
 */
function findOverflow(root: Element, check: Check): string[] {
    const slack = 1;
    const problems: string[] = [];
    const reported: Element[] = [];

    const name = (el: Element): string => {
        const testId = el.getAttribute('data-test');
        const text = (el.textContent ?? '')
            .trim()
            .replace(/\s+/g, ' ')
            .slice(0, 50);

        return `<${el.tagName.toLowerCase()}${testId ? ` data-test="${testId}"` : ''}> "${text}"`;
    };
    const edges = (rect: DOMRect): string =>
        `x ${Math.round(rect.left)}–${Math.round(rect.right)}, y ${Math.round(rect.top)}–${Math.round(rect.bottom)}`;
    const scrolls = (el: Element, axis: 'overflowX' | 'overflowY'): boolean =>
        ['auto', 'scroll'].includes(getComputedStyle(el)[axis]);

    const box = root.getBoundingClientRect();
    const outsideWindow =
        box.left < -slack ||
        box.right > window.innerWidth + slack ||
        (check.window === 'both' &&
            (box.top < -slack || box.bottom > window.innerHeight + slack));

    if (outsideWindow) {
        problems.push(
            `${name(root)} (${edges(box)}) does not fit the ${window.innerWidth}×${window.innerHeight} window`,
        );
    }

    if (
        check.window === 'width' &&
        document.documentElement.scrollWidth > window.innerWidth + slack
    ) {
        problems.push(
            `the page scrolls sideways (${document.documentElement.scrollWidth} px wide)`,
        );
    }

    for (const el of Array.from(root.querySelectorAll('*'))) {
        const rect = el.getBoundingClientRect();

        if (
            el.closest('option, optgroup') ||
            (rect.width === 0 && rect.height === 0) ||
            getComputedStyle(el).visibility === 'hidden' ||
            reported.some((parent) => parent.contains(el))
        ) {
            continue;
        }

        let inScroller = false;

        for (let up = el.parentElement; up; up = up.parentElement) {
            if (scrolls(up, 'overflowY')) {
                inScroller = true;
                break;
            }

            if (up === root) {
                break;
            }
        }

        const sideways =
            rect.left < box.left - slack || rect.right > box.right + slack;
        const upOrDown =
            !inScroller &&
            (rect.top < box.top - slack || rect.bottom > box.bottom + slack);

        if (sideways || upOrDown) {
            reported.push(el);
            problems.push(
                `${name(el)} (${edges(rect)}) is outside ${name(root).slice(0, 60)} (${edges(box)})`,
            );
        }
    }

    for (const el of [root, ...Array.from(root.querySelectorAll('*'))]) {
        if (
            scrolls(el, 'overflowX') &&
            el.scrollWidth > el.clientWidth + slack
        ) {
            problems.push(
                `${name(el)} scrolls sideways (${el.scrollWidth} px of content in ${el.clientWidth} px)`,
            );
        }
    }

    return problems;
}

/** Waits for the open animation (the dialog zooms in), then checks the box. */
async function expectInside(
    target: Locator,
    what: string,
    check: Check = { window: 'both' },
): Promise<void> {
    await expect(target).toBeVisible();
    await target.evaluate((el) =>
        Promise.all(
            el
                .getAnimations({ subtree: true })
                .filter((a) => a.effect?.getTiming().iterations !== Infinity)
                .map((a) => a.finished.catch(() => undefined)),
        ),
    );

    expect(await target.evaluate(findOverflow, check), what).toEqual([]);
}

function dialog(page: Page, title: string | RegExp): Locator {
    return page.getByRole('dialog').filter({
        has: page.getByRole('heading', { name: title }),
    });
}

/**
 * Picks the till and opens its shift when none is open. The session is
 * shared (sign-in.setup.ts), so it may remember the till an earlier test
 * used, and that till may still have its shift open.
 */
async function useTill(page: Page, code: string): Promise<void> {
    const picker = page.locator('[data-test=till-picker]');
    const current = page.locator('[data-test=till-code]');

    await expect(picker.or(current)).toBeVisible();

    if (await current.isVisible()) {
        await page.getByRole('button', { name: 'Change till' }).click();
    }

    await expectInside(picker, 'till picker', { window: 'width' });
    await picker.locator('[data-test=till-option]', { hasText: code }).click();
    await expect(page.locator('[data-test=till-code]')).toHaveText(code);

    const opening = page.locator('[data-test=opening-cash]');
    const search = page.locator('[data-test=pos-search]');
    await expect(opening.or(search)).toBeVisible();

    if (await opening.isVisible()) {
        await expectInside(
            page.locator('[data-test=shift-open]'),
            'opening a shift',
            { window: 'width' },
        );
        await opening.fill('1000');
        await page.locator('[data-test=open-shift]').click();
        await expect(search).toBeVisible();
    }
}

async function addItem(page: Page, query: string): Promise<void> {
    const lines = page.locator('[data-test=cart-line]');
    const before = await lines.count();

    await page.locator('[data-test=pos-search]').fill(query);
    await page
        .locator('[data-test=search-result]', { hasText: query })
        .first()
        .click();
    await expect(lines).toHaveCount(before + 1);
}

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

            await test.step('Prescription dialog', async () => {
                await page.locator('[data-test=prescription-button]').click();
                const rx = page.locator('[data-test=prescription-dialog]');
                await expectInside(rx, 'Prescription dialog');
                await page.keyboard.press('Escape');
                await expect(rx).toBeHidden();
            });

            await test.step('Pay dialog', async () => {
                await page.keyboard.press('F9');
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
                await expectInside(
                    summary.locator('.page'),
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
