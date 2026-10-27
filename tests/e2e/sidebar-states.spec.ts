import { mkdirSync, writeFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import type { Browser, Locator, Page } from '@playwright/test';
import { measure } from './helpers';
import type { Measure } from './helpers';

/**
 * The sidebar's item states under every colour theme, light and dark: the
 * admin panel (/admin/expiry, so Expiry is active and carries a badge) and
 * the Vue pages' sidebar (/dashboard as the clinic's cashier, Dashboard
 * active). Measures the colours the browser actually draws, text and icon
 * against whatever lies under them, for a plain item, a hovered one, the
 * active one and the active one hovered, plus the group labels, the badges
 * and the keyboard focus ring. A themed preset must reach WCAG AA (text
 * 4.5:1, icons and the ring 3:1); classic is Filament's and the starter
 * kit's own look, kept as it was for retail, so its figures are only
 * recorded. In Chromium it saves screenshots for review to
 * storage/app/screenshots/sidebar (not committed).
 */

const presets = ['clinic_teal', 'ocean_blue', 'plum', 'slate', 'classic'];
const modes = ['light', 'dark'] as const;
const screenshots = 'storage/app/screenshots/sidebar';

/** Sets the shop's colour theme as the owner does: Settings → Appearance, pick, Save. */
async function usePreset(page: Page, preset: string): Promise<void> {
    await page.goto('/settings/appearance');
    await page.locator(`[data-test=theme-${preset}]`).click();
    const save = page.locator('[data-test=save-theme-button]');

    if (await save.isEnabled()) {
        await save.click();
        await expect(page.getByText('Colour theme set to')).toBeVisible();
    }
}

async function expectTheme(page: Page, preset: string): Promise<void> {
    const html = page.locator('html');

    if (preset === 'classic') {
        await expect(html).not.toHaveAttribute('data-pos-theme');
    } else {
        await expect(html).toHaveAttribute('data-pos-theme', preset);
    }
}

/** Fails a themed preset's figures below AA; classic's are only recorded. */
function check(
    preset: string,
    where: string,
    figures: Record<string, Measure>,
): void {
    test.info().annotations.push({
        type: `${preset} ${where}`,
        description: Object.entries(figures)
            .map(
                ([state, m]) =>
                    `${state} ${m.text.toFixed(2)}${m.icon === null ? '' : `/${m.icon.toFixed(2)}`}`,
            )
            .join(', '),
    });

    if (preset === 'classic') {
        return;
    }

    for (const [state, m] of Object.entries(figures)) {
        expect
            .soft(m.text, `${preset} ${where}: ${state} text`)
            .toBeGreaterThanOrEqual(4.5);

        if (m.icon !== null) {
            expect
                .soft(m.icon, `${preset} ${where}: ${state} icon`)
                .toBeGreaterThanOrEqual(3);
        }
    }
}

const report: Record<string, Record<string, Measure>> = {};

/** The clinic's cashier, signed in once per browser (signing in per test runs into the login rate limit). */
let cashierState: string | null = null;

async function signInCashier(
    browser: Browser,
    baseURL: string,
    browserName: string,
): Promise<string> {
    if (cashierState === null) {
        const context = await browser.newContext({
            // Not the project's owner session.
            storageState: { cookies: [], origins: [] },
            serviceWorkers: 'block',
            baseURL,
        });
        const page = await context.newPage();
        await page.goto('/login');
        await page.locator('input[name=email]').fill('cashier@clinic.test');
        await page.locator('input[name=password]').fill('password');
        await page.locator('[data-test=login-button]').click();
        await expect(page).not.toHaveURL(/\/login/);
        cashierState = `storage/e2e/cashier-${browserName}.json`;
        await context.storageState({ path: cashierState });
        await context.close();
    }

    return cashierState;
}

test.afterAll(async ({ browser }, testInfo) => {
    // Back to the clinic's own theme for the specs that follow.
    const context = await browser.newContext({
        storageState: 'storage/e2e/owner.json',
        serviceWorkers: 'block',
        baseURL: testInfo.project.use.baseURL,
    });
    await usePreset(await context.newPage(), 'clinic_teal');
    await context.close();
});

for (const preset of presets) {
    for (const mode of modes) {
        test(`the admin sidebar's states read under ${preset}, ${mode}`, async ({
            page,
            browserName,
        }) => {
            await page.setViewportSize({ width: 1440, height: 900 });
            await usePreset(page, preset);

            await page.addInitScript((theme) => {
                window.localStorage.setItem('theme', theme);
            }, mode);
            await page.goto('/admin/expiry');
            await expectTheme(page, preset);
            await expect(page.locator('html')).toHaveClass(
                mode === 'dark' ? /\bdark\b/ : /^(?!.*\bdark\b)/,
            );

            const item = (label: string): Locator =>
                page
                    .locator('.fi-sidebar-item', {
                        has: page.locator('.fi-sidebar-item-label', {
                            hasText: new RegExp(`^\\s*${label}\\s*$`),
                        }),
                    })
                    .first()
                    .locator('> .fi-sidebar-item-btn');
            const states = (btn: Locator): Promise<Measure> =>
                measure(
                    btn.locator('> .fi-sidebar-item-label'),
                    btn.locator('> .fi-icon'),
                );

            // Expiry is listed under Stock and Reports; the Stock one is this page.
            const active = page.locator(
                '.fi-sidebar-item.fi-active > .fi-sidebar-item-btn',
            );
            await expect(active).toHaveCount(1);
            await expect(active).toContainText('Expiry');
            const hovered = item('Stock levels');

            const plain = await states(item('Batches'));
            const group = await measure(
                page.locator('.fi-sidebar-group-label', {
                    hasText: /^\s*Stock\s*$/,
                }),
            );
            const badges = page.locator('.fi-sidebar-item-badge-ctn .fi-badge');
            await expect(badges.first()).toBeVisible();
            const badgeFigures: Record<string, Measure> = {};
            for (let i = 0; i < (await badges.count()); i++) {
                badgeFigures[`badge ${i + 1}`] = await measure(badges.nth(i));
            }

            await hovered.hover();
            await page.waitForTimeout(250);
            const hover = await states(hovered);
            const activeIdle = await states(active);

            if (browserName === 'chromium') {
                mkdirSync(screenshots, { recursive: true });
                await page.locator('.fi-sidebar').screenshot({
                    path: `${screenshots}/admin-${preset}-${mode}.png`,
                });
            }

            await active.hover();
            await page.waitForTimeout(250);
            const activeHover = await states(active);

            const figures = {
                plain,
                group,
                hover,
                active: activeIdle,
                'active+hover': activeHover,
                ...badgeFigures,
            };
            report[`admin ${preset} ${mode} ${browserName}`] = figures;
            check(preset, `admin ${mode}`, figures);

            if (preset !== 'classic') {
                // Hover and active: white text, the active item semibold; no dark text on hover.
                expect(hover.colour).toBe('rgb(255, 255, 255)');
                expect(activeIdle.colour).toBe('rgb(255, 255, 255)');
                expect(activeIdle.weight).toBe('600');
                const bar = await active.evaluate(
                    (el) => getComputedStyle(el, '::before').width,
                );
                expect(bar).toBe('3px');

                // Keyboard focus: a 2px ring in the accent, 2px off the item.
                await item('Batches').focus();
                await page.keyboard.press('Tab');
                const ring = await page.evaluate(() => {
                    const style = getComputedStyle(document.activeElement!);

                    return [
                        style.outlineStyle,
                        style.outlineWidth,
                        style.outlineOffset,
                    ];
                });
                expect(ring).toEqual(['solid', '2px', '2px']);
            }
        });
    }
}

for (const preset of presets) {
    for (const mode of modes) {
        test(`the settings pages' sidebar states read under ${preset}, ${mode}`, async ({
            browser,
            page,
            browserName,
        }) => {
            await page.setViewportSize({ width: 1440, height: 900 });
            await usePreset(page, preset);

            // The clinic's cashier: the owner's /dashboard is the admin panel's.
            const baseURL = test.info().project.use.baseURL!;
            const context = await browser.newContext({
                storageState: await signInCashier(
                    browser,
                    baseURL,
                    browserName,
                ),
                serviceWorkers: 'block',
                baseURL,
                viewport: { width: 1440, height: 900 },
            });
            await context.addCookies([
                { name: 'appearance', value: mode, url: baseURL },
            ]);
            const cashier = await context.newPage();
            await cashier.addInitScript((appearance) => {
                window.localStorage.setItem('appearance', appearance);
            }, mode);

            await cashier.goto('/dashboard');
            await expectTheme(cashier, preset);

            const active = cashier.locator(
                '[data-sidebar=menu-button][data-active=true]',
            );
            await expect(active).toContainText('Dashboard');
            const hovered = cashier.locator(
                '[data-sidebar=footer] [data-sidebar=menu-button]',
            );

            const group = await measure(
                cashier.locator('[data-sidebar=group-label]').first(),
            );
            const activeIdle = await measure(
                active.locator('span').last(),
                active.locator('svg').first(),
            );
            await hovered.hover();
            await cashier.waitForTimeout(250);
            const hover = await measure(
                hovered.locator('span.truncate').first(),
            );
            await expect(cashier.locator('html')).toHaveClass(
                mode === 'dark' ? /\bdark\b/ : /^(?!.*\bdark\b)/,
            );

            if (browserName === 'chromium') {
                mkdirSync(screenshots, { recursive: true });
                await cashier
                    .locator('[data-sidebar=sidebar]:visible')
                    .first()
                    .screenshot({
                        path: `${screenshots}/settings-${preset}-${mode}.png`,
                    });
            }

            await active.hover();
            await cashier.waitForTimeout(250);
            const activeHover = await measure(
                active.locator('span').last(),
                active.locator('svg').first(),
            );

            const figures = {
                group,
                hover,
                active: activeIdle,
                'active+hover': activeHover,
            };
            report[`settings ${preset} ${mode} ${browserName}`] = figures;
            check(preset, `settings ${mode}`, figures);

            if (preset !== 'classic') {
                expect(activeIdle.colour).toBe('rgb(255, 255, 255)');
                expect(activeIdle.weight).toBe('600');
                expect(hover.colour).toBe('rgb(255, 255, 255)');
            }

            await context.close();

            if (browserName === 'chromium') {
                writeFileSync(
                    `${screenshots}/contrast.json`,
                    JSON.stringify(report, null, 2),
                );
            }
        });
    }
}
