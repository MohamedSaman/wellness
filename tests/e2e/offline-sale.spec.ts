import { expect, test } from '@playwright/test';
import {
    expectReady,
    goOffline,
    resetTill,
    sellOffline,
    useTill,
} from './helpers';

/**
 * A till that loses its internet connection (docs/deploy/TILLS.md, "Check
 * offline billing"): approved for offline sales, it keeps billing from its
 * own catalog with the connection cut, holds the sale, and uploads it as
 * soon as the connection is back; the server then has the sale and System
 * health shows nothing waiting; a patient added on the till offline reaches
 * the server once, with the sale. The page stays open throughout, as on a
 * till PC (the service worker that serves it after a reload is blocked in
 * these tests).
 */

test.use({ viewport: { width: 1280, height: 800 } });

test('a till keeps billing offline and syncs the sale on reconnect', async ({
    page,
    context,
}) => {
    const badge = page.locator('[data-test=connection-badge]');
    const syncNow = page.locator('[data-test=sync-now]');

    await resetTill(context, 'T1');
    await page.goto('/pos');
    await useTill(page, 'T1');

    await test.step('The till prepares itself for offline sales', async () => {
        await expectReady(page);
        await expect(badge).toHaveText(/^\s*Online/);
    });

    let invoice = '';
    // A patient added at the till while it is offline (branch 13): one per browser, so runs never meet.
    const patient = `Offline Patient ${test.info().project.name} ${Date.now()}`;

    await test.step('The connection drops: the sale is made on the till', async () => {
        await goOffline(page, context);

        invoice = await sellOffline(page, { name: patient, age: '8m' });
        expect(invoice).toMatch(/^T1-\d{6}$/);
        await page.locator('[data-test=new-sale]').click();

        await expect(badge).toHaveText(/1 sale waiting to sync/);
    });

    await test.step('The connection is back: the sale uploads by itself', async () => {
        await context.setOffline(false);
        await expect(badge).toHaveText(/^\s*Online\s*$/, { timeout: 60_000 });
    });

    await test.step('The server has it, and System health shows nothing waiting', async () => {
        const admin = await context.newPage();

        await admin.goto('/admin/sales');
        await expect(admin.getByText(invoice)).toBeVisible();

        // The patient added offline is on the server once, with this sale and their age at it.
        await admin.goto('/admin/patients');
        await expect(admin.getByText(patient)).toHaveCount(1);
        await admin.getByText(patient).click();
        await expect(admin.getByText(invoice)).toBeVisible();
        await expect(admin.getByText('8 m').first()).toBeVisible();

        // The till reports what it holds with its next request: sync once more.
        await syncNow.click();
        await expect(syncNow).toHaveText(/Refresh now/, { timeout: 60_000 });
        await admin.goto('/admin/system-health');
        await expect(admin.locator('[data-till=T1] td').nth(3)).toHaveText(
            /^\s*0\b/,
        );
        await admin.close();
    });
});
