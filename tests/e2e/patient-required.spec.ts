import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { addItem, dialog, useTill } from './helpers';

/**
 * A clinic that requires the patient on every sale (docs/PHARMACY.md,
 * branch 13, patient_on_sale 'always'): the till's Patient & prescription
 * step cannot be skipped and payment waits until a patient is chosen. The
 * setting is put back to "Ask, can skip" afterwards, since the other specs
 * skip the step.
 */

test.use({ viewport: { width: 1280, height: 800 } });

async function patientOnSale(page: Page, label: RegExp): Promise<void> {
    await page.goto('/admin/shop-settings');
    await page.getByRole('radio', { name: label }).check();
    await page.getByRole('button', { name: 'Save settings' }).click();
    await expect(page.getByText('Settings saved')).toBeVisible();
}

test.afterEach(async ({ page }) => {
    await patientOnSale(page, /^Ask, can skip/);
});

test('a required patient cannot be skipped and holds payment', async ({
    page,
}) => {
    await patientOnSale(page, /^Required for every sale/);

    await page.goto('/pos');
    await useTill(page, 'T1');
    await addItem(page, 'Panadol');

    const step = page.locator('[data-test=prescription-dialog]');
    const pay = dialog(page, 'Payment');

    await expect(async () => {
        await page.keyboard.press('F9');
        await expect(step).toBeVisible({ timeout: 1_000 });
    }).toPass({ timeout: 30_000 });

    await expect(step.locator('[data-test=patient-required]')).toHaveText(
        'Required',
    );
    await expect(step.locator('[data-test=prescription-message]')).toHaveText(
        'Choose or add the patient for this sale.',
    );
    await expect(step.locator('[data-test=patient-skip]')).toBeDisabled();
    await expect(
        step.locator('[data-test=prescription-continue]'),
    ).toBeDisabled();

    // Esc does not skip a required patient: it goes back to the sale, and F9 asks again.
    await page.keyboard.press('Escape');
    await expect(step).toBeHidden();
    await page.keyboard.press('F9');
    await expect(step).toBeVisible();
    await expect(pay).toBeHidden();

    await step.locator('[data-test=patient-name]').fill('Sunil');
    await expect(step.locator('[data-test=patient-result]')).toHaveCount(1);
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    await expect(step.locator('[data-test=patient-chosen]')).toContainText(
        'Sunil Fernando',
    );
    await expect(
        step.locator('[data-test=prescription-continue]'),
    ).toBeEnabled();
    await step.locator('[data-test=prescription-continue]').click();

    await expect(pay).toBeVisible();
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: 'Clear cart' }).click();
});
