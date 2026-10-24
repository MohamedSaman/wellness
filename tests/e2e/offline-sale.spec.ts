import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * A till that loses its internet connection (docs/deploy/TILLS.md, "Check
 * offline billing"): registered for offline sales, it keeps billing from its
 * own catalog with the connection cut, holds the sale, and uploads it as
 * soon as the connection is back; the server then has the sale and System
 * health shows nothing waiting. The page stays open throughout, as on a
 * till PC (the service worker that serves it after a reload is blocked in
 * these tests).
 */

test.use({ viewport: { width: 1280, height: 800 } });

/** Picks the till and opens its shift when none is open (as in till-layout.spec.ts). */
async function useTill(page: Page, code: string): Promise<void> {
    const picker = page.locator('[data-test=till-picker]');
    const current = page.locator('[data-test=till-code]');

    await expect(picker.or(current)).toBeVisible();

    if (await current.isVisible()) {
        if ((await current.textContent())?.trim() === code) {
            return;
        }

        await page.getByRole('button', { name: 'Change till' }).click();
    }

    await picker.locator('[data-test=till-option]', { hasText: code }).click();
    await expect(current).toHaveText(code);

    const opening = page.locator('[data-test=opening-cash]');
    const search = page.locator('[data-test=pos-search]');
    await expect(opening.or(search)).toBeVisible();

    if (await opening.isVisible()) {
        await opening.fill('1000');
        await page.locator('[data-test=open-shift]').click();
        await expect(search).toBeVisible();
    }
}

test('a till keeps billing offline and syncs the sale on reconnect', async ({
    page,
    context,
}) => {
    const badge = page.locator('[data-test=connection-badge]');
    const syncNow = page.locator('[data-test=sync-now]');

    await page.goto('/pos');
    await useTill(page, 'T1');

    await test.step('Set up offline sales on this till', async () => {
        const register = page.locator('[data-test=register-till]');
        await expect(register.or(syncNow)).toBeVisible();

        if (await register.isVisible()) {
            await register.click();
        }

        // The first sync downloads the catalog and reserves invoice numbers.
        await expect(syncNow).toHaveText(/Sync now/, { timeout: 60_000 });
        await syncNow.click();
        await expect(syncNow).toHaveText(/Sync now/, { timeout: 60_000 });
        await expect(badge).toHaveText(/^\s*Online/);
    });

    let invoice = '';

    await test.step('The connection drops: the sale is made on the till', async () => {
        await context.setOffline(true);
        await expect(badge).toHaveText(/^\s*Offline/);

        await page.locator('[data-test=pos-search]').fill('Panadol');
        await page
            .locator('[data-test=search-result]', { hasText: 'Panadol' })
            .first()
            .click();
        await expect(page.locator('[data-test=cart-line]')).toHaveCount(1);

        const pay = page.getByRole('dialog').filter({
            has: page.getByRole('heading', { name: 'Payment' }),
        });
        // F9 waits for the cart's price (worked out on the till while offline): press again until it opens.
        await expect(async () => {
            await page.keyboard.press('F9');
            await expect(pay).toBeVisible({ timeout: 1_000 });
        }).toPass({ timeout: 30_000 });
        await pay.getByRole('button', { name: 'Exact' }).click();
        await pay.locator('[data-test=complete-sale]').click();

        const done = page.locator('[data-test=sale-done]');
        await expect(done.locator('[data-test=saved-offline]')).toBeVisible();
        invoice = ((await done.getByText(/^Invoice /).textContent()) ?? '')
            .replace('Invoice', '')
            .trim();
        expect(invoice).toMatch(/^T1-\d{6}$/);
        await done.locator('[data-test=new-sale]').click();

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

        // The till reports what it holds with its next request: sync once more.
        await syncNow.click();
        await expect(syncNow).toHaveText(/Sync now/, { timeout: 60_000 });
        await admin.goto('/admin/system-health');
        await expect(admin.locator('[data-till=T1] td').nth(3)).toHaveText(
            /^\s*0\b/,
        );
        await admin.close();
    });
});
