import { mkdirSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { expectInside, measure } from './helpers';

/**
 * The sign-in page as a guest sees it, for the demo clinic (Clinic Teal, no
 * logo uploaded, so its mark is the medical cross): "/" leads to it, the
 * shop's name is on the tab, the panel and the icon, nothing of Laravel's
 * shows, the page fits a desktop (side by side) and a phone (stacked)
 * without scrolling sideways, every text reads (4.5:1), the password can be
 * shown, and nothing breaks the Content-Security-Policy. In Chromium it
 * saves screenshots for review to storage/app/screenshots/login (not
 * committed).
 */

const viewports = [
    { width: 1440, height: 900 },
    { width: 390, height: 844 },
];
const screenshots = 'storage/app/screenshots/login';

test.use({ storageState: { cookies: [], origins: [] } });

for (const viewport of viewports) {
    for (const mode of ['light', 'dark'] as const) {
        test(`the sign-in page is the shop's at ${viewport.width} px, ${mode}`, async ({
            page,
            browserName,
            baseURL,
        }) => {
            const blocked: string[] = [];
            page.on('console', (message) => {
                if (
                    /Content.Security.Policy|Refused to/i.test(message.text())
                ) {
                    blocked.push(message.text());
                }
            });

            await page.setViewportSize(viewport);
            await page
                .context()
                .addCookies([
                    { name: 'appearance', value: mode, url: baseURL! },
                ]);
            await page.addInitScript((appearance) => {
                window.localStorage.setItem('appearance', appearance);
            }, mode);

            await page.goto('/');
            await expect(page).toHaveURL(/\/login$/);
            await expect(page.locator('html')).toHaveAttribute(
                'data-pos-theme',
                'clinic_teal',
            );
            await expect(page.locator('html')).toHaveClass(
                mode === 'dark' ? /\bdark\b/ : /^(?!.*\bdark\b)/,
            );

            // The shop's name, tagline and mark; its name on the tab and its icon.
            const name = (
                await page.locator('[data-test=brand-name]').innerText()
            ).trim();
            expect(name).not.toBe('');
            await expect(page).toHaveTitle(`Sign in - ${name}`);
            await expect(page.locator('[data-test=brand-tagline]')).toHaveText(
                'Pharmacy & OPD system',
            );
            await expect(
                page.locator('[data-test=brand-mark] svg'),
            ).toBeVisible();
            await expect(page.locator('link[rel=icon]')).toHaveAttribute(
                'href',
                '/favicon-clinic.svg',
            );

            // Nothing of the starter kit.
            await expect(page.locator('body')).not.toContainText(
                /laravel|laracasts|deploy now/i,
            );
            await expect(
                page.locator('a[href*="laravel.com"], a[href*="laracasts"]'),
            ).toHaveCount(0);

            const poweredBy = page.locator('[data-test=powered-by]');
            await expect(poweredBy).toHaveAttribute(
                'href',
                'https://webxkey.lk',
            );
            await expect(poweredBy).toHaveAttribute('target', '_blank');
            await expect(poweredBy).toHaveAttribute('rel', /noopener/);

            // Side by side on a desktop, the shop on top on a phone; nothing scrolls sideways.
            const panel = await page
                .locator('[data-test=brand-panel]')
                .boundingBox();
            const card = await page
                .locator('[data-test=auth-card]')
                .boundingBox();
            if (viewport.width >= 1024) {
                expect(panel!.x + panel!.width).toBeLessThanOrEqual(card!.x);
                expect(panel!.width).toBeCloseTo(viewport.width / 2, -1);
            } else {
                expect(panel!.y + panel!.height).toBeLessThanOrEqual(card!.y);
            }
            await expectInside(
                page.locator('[data-test=brand-panel]').locator('xpath=..'),
                `sign-in ${viewport.width} ${mode}`,
                { window: 'width' },
            );

            // Every text reads: the panel, the card, the button, the footer.
            for (const [what, target] of [
                ['shop name', '[data-test=brand-name]'],
                ['tagline', '[data-test=brand-tagline]'],
                ['title', '[data-test=auth-card] h1'],
                ['description', '[data-test=auth-card] h1 + p'],
                ['email label', 'label[for=email]'],
                ['sign-in button', '[data-test=login-button]'],
                ['footer', 'footer'],
                ['powered by', '[data-test=powered-by]'],
            ] as const) {
                const { text } = await measure(page.locator(target).first());
                expect
                    .soft(text, `${what} at ${viewport.width} ${mode}`)
                    .toBeGreaterThanOrEqual(4.5);
            }
            const { text: mark } = await measure(
                page.locator('[data-test=brand-mark]'),
            );
            expect
                .soft(mark, `the mark at ${viewport.width} ${mode}`)
                .toBeGreaterThanOrEqual(3);

            // Show and hide the password.
            const password = page.locator('#password');
            await password.fill('secret');
            await page.getByRole('button', { name: 'Show password' }).click();
            await expect(password).toHaveAttribute('type', 'text');
            await page.getByRole('button', { name: 'Hide password' }).click();
            await expect(password).toHaveAttribute('type', 'password');
            await password.fill('');

            if (browserName === 'chromium') {
                mkdirSync(screenshots, { recursive: true });
                await page.screenshot({
                    path: `${screenshots}/login-${mode}-${viewport.width}.png`,
                    fullPage: true,
                });
            }

            expect(blocked).toEqual([]);
        });
    }
}
