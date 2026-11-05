import { expect, test } from '@playwright/test';
import type { Request } from '@playwright/test';
import {
    expectInside,
    expectReady,
    goOffline,
    offlineMeta,
    resetTill,
    sellOffline,
    useTill,
} from './helpers';

/**
 * Offline ready by default (docs/deploy/TILLS.md): a till the owner approved
 * prepares itself with no click, and refreshes when the connection returns;
 * one never approved, or revoked, does not. T1 is approved in the demo
 * clinic, T2 is not.
 */

test.use({ viewport: { width: 1280, height: 800 } });

const readiness = '[data-test=offline-readiness]';
const called = (requests: Request[], path: string): number =>
    requests.filter((request) => request.url().includes(path)).length;

test('an approved till is ready with no click, and sells when the network drops', async ({
    page,
    context,
}) => {
    const requests: Request[] = [];
    page.on('request', (request) => requests.push(request));

    await resetTill(context, 'T1');
    await page.goto('/pos');
    await useTill(page, 'T1');
    await expectReady(page);

    await expect(page.locator(readiness)).toHaveText(/Ready for offline/);
    await expect(page.locator('[data-test=register-till]')).toHaveCount(0);
    expect(called(requests, '/pos/till/prepare')).toBe(1);
    expect(called(requests, '/pos/till/register')).toBe(0);

    await goOffline(page, context);
    expect(await sellOffline(page)).toMatch(/^T1-\d{6}$/);
    await page.locator('[data-test=new-sale]').click();

    await test.step('After reconnecting, the catalog and invoice numbers refresh by themselves', async () => {
        const before = await offlineMeta(page);

        // Almost out of reserved numbers, as after a long outage.
        await page.evaluate(
            () =>
                new Promise<void>((resolve, reject) => {
                    const open = indexedDB.open('pos-offline');

                    open.onerror = () => reject(open.error);
                    open.onsuccess = () => {
                        const tx = open.result.transaction('meta', 'readwrite');
                        const store = tx.objectStore('meta');
                        const read = store.get('invoiceNumbers');

                        read.onsuccess = () => {
                            const numbers = read.result.value;
                            const range = numbers.ranges.at(-1);

                            numbers.next = range.last - 2;
                            store.put({
                                key: 'invoiceNumbers',
                                value: numbers,
                            });
                        };
                        tx.oncomplete = () => {
                            open.result.close();
                            resolve();
                        };
                    };
                }),
        );
        requests.length = 0;

        await context.setOffline(false);

        await expect(page.locator('[data-test=connection-badge]')).toHaveText(
            /^\s*Online\s*$/,
            { timeout: 60_000 },
        );
        await expect(async () => {
            const after = await offlineMeta(page);

            expect(after.lastCatalogSync).toBeGreaterThan(
                before.lastCatalogSync ?? 0,
            );
            expect(after.invoiceNumbersLeft).toBeGreaterThanOrEqual(50);
        }).toPass({ timeout: 60_000 });

        expect(called(requests, '/pos/sync/catalog')).toBeGreaterThan(0);
        expect(called(requests, '/pos/sync/invoice-block')).toBeGreaterThan(0);
        expect(called(requests, '/pos/till/prepare')).toBe(0);
        await expectReady(page);
    });
});

test('a till never approved does not prepare itself', async ({
    page,
    browser,
    baseURL,
}) => {
    const requests: Request[] = [];
    page.on('request', (request) => requests.push(request));

    await test.step('The owner sees the approval button, and nothing happens by itself', async () => {
        await page.goto('/pos');
        await useTill(page, 'T2');

        await expect(page.locator(readiness)).toHaveAttribute(
            'data-state',
            'unavailable',
        );
        await expect(page.locator(readiness)).toHaveText(
            /Offline not available on this till - ask the owner/,
        );
        await expect(page.locator('[data-test=register-till]')).toBeVisible();
        await page.waitForTimeout(1_500);

        expect(called(requests, '/pos/till/prepare')).toBe(0);
        expect(called(requests, '/pos/sync/')).toBe(0);
        expect(
            (await offlineMeta(page)).keys.filter((key) =>
                key.startsWith('token:'),
            ),
        ).toEqual([]);
    });

    await test.step('A cashier is told to ask the owner, with no button', async () => {
        const context = await browser.newContext({
            baseURL,
            viewport: { width: 1280, height: 800 },
            storageState: { cookies: [], origins: [] },
        });
        const cashier = await context.newPage();

        await cashier.goto('/login');
        await cashier.locator('input[name=email]').fill('opd@clinic.test');
        await cashier.locator('input[name=password]').fill('password');
        await cashier.locator('[data-test=login-button]').click();
        await expect(cashier).not.toHaveURL(/\/login/);
        await cashier.goto('/pos');
        await useTill(cashier, 'T2');

        await expect(cashier.locator(readiness)).toHaveText(
            /Offline not available on this till - ask the owner/,
        );
        await expect(cashier.locator('[data-test=register-till]')).toHaveCount(
            0,
        );
        await context.close();
    });
});

test('a revoked till stops selling offline, and a reset till registers itself on its next load', async ({
    page,
    context,
}) => {
    const requests: Request[] = [];
    page.on('request', (request) => requests.push(request));

    await resetTill(context, 'T1');
    await page.goto('/pos');
    await useTill(page, 'T1');
    await expectReady(page);

    await test.step('Revoked: the next sync is refused and the till says so', async () => {
        await resetTill(context, 'T1', 'Revoke offline access');
        requests.length = 0;

        await page.locator('[data-test=sync-now]').click();

        await expect(page.locator(readiness)).toHaveAttribute(
            'data-state',
            'unavailable',
        );
        expect(called(requests, '/pos/till/register')).toBe(0);
    });

    await test.step('Still revoked after a reload: it never registers itself', async () => {
        requests.length = 0;
        await page.reload();

        await expect(page.locator(readiness)).toHaveAttribute(
            'data-state',
            'unavailable',
        );
        expect(called(requests, '/pos/till/prepare')).toBe(0);
    });

    await test.step('Reset by the owner: ready on the next load, with no second step', async () => {
        await resetTill(context, 'T1');
        await page.reload();
        await expectReady(page);
    });
});

test('the Tills table fits a 1280 px window, the row actions in a menu', async ({
    page,
}) => {
    await page.goto('/admin/devices');

    await expectInside(page.locator('.fi-ta-ctn').first(), 'Tills table', {
        window: 'width',
    });
});
