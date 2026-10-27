import { expect } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';

/**
 * What the end-to-end specs share: the check that nothing sticks out of its
 * box or scrolls sideways, and the till steps (pick a till, add an item).
 */

export type Check = {
    /** Must the box fit the window's height too (dialogs), or only its width (pages)? */
    window: 'both' | 'width';
};

/**
 * Runs in the page: what sticks out of `root`. A child may only leave the
 * box upwards or downwards inside a scrolling area (a long list); nothing may
 * leave it sideways, and nothing may scroll sideways.
 */
export function findOverflow(root: Element, check: Check): string[] {
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
            // Screen-reader only (clipped to nothing), like a checkbox's native input (reka-ui).
            getComputedStyle(el).clip === 'rect(0px, 0px, 0px, 0px)' ||
            getComputedStyle(el).clipPath === 'inset(50%)' ||
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
export async function expectInside(
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

export function dialog(page: Page, title: string | RegExp): Locator {
    return page.getByRole('dialog').filter({
        has: page.getByRole('heading', { name: title }),
    });
}

/**
 * Picks the till and opens its shift when none is open. The session is
 * shared (sign-in.setup.ts), so it may remember the till an earlier test
 * used, and that till may still have its shift open.
 */
export async function useTill(page: Page, code: string): Promise<void> {
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

export async function addItem(page: Page, query: string): Promise<void> {
    const lines = page.locator('[data-test=cart-line]');
    const before = await lines.count();

    await page.locator('[data-test=pos-search]').fill(query);
    await page
        .locator('[data-test=search-result]', { hasText: query })
        .first()
        .click();
    await expect(lines).toHaveCount(before + 1);
}

export type Measure = {
    text: number;
    icon: number | null;
    colour: string;
    weight: string;
};

/**
 * Runs in the page: the contrast of an element's text colour (and of its
 * icon's, if any) against everything painted under it, every background
 * up to the first opaque one composited on a 1×1 canvas, so any CSS colour
 * (oklch, color-mix, alpha) counts as the browser draws it.
 */
export async function measure(
    label: Locator,
    icon?: Locator,
): Promise<Measure> {
    const one = (el: Element): [number, string, string] => {
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = 1;
        const ctx = canvas.getContext('2d', { willReadFrequently: true })!;
        const pixel = (): number[] =>
            Array.from(ctx.getImageData(0, 0, 1, 1).data);
        const layers: string[] = [];

        for (let up: Element | null = el; up; up = up.parentElement) {
            const background = getComputedStyle(up).backgroundColor;
            ctx.clearRect(0, 0, 1, 1);
            ctx.fillStyle = background;
            ctx.fillRect(0, 0, 1, 1);
            const alpha = pixel()[3];

            if (alpha > 0) {
                layers.unshift(background);
            }

            if (alpha === 255) {
                break;
            }
        }

        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, 1, 1);
        for (const layer of layers) {
            ctx.fillStyle = layer;
            ctx.fillRect(0, 0, 1, 1);
        }
        const under = pixel();
        const colour = getComputedStyle(el).color;
        ctx.fillStyle = colour;
        ctx.fillRect(0, 0, 1, 1);
        const over = pixel();

        const luminance = ([r, g, b]: number[]): number =>
            [r, g, b]
                .map((c) => c / 255)
                .map((c) =>
                    c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4,
                )
                .reduce(
                    (sum, c, i) => sum + c * [0.2126, 0.7152, 0.0722][i],
                    0,
                );
        const [light, dark] = [luminance(under), luminance(over)].sort(
            (a, b) => b - a,
        );

        return [
            (light + 0.05) / (dark + 0.05),
            colour,
            getComputedStyle(el).fontWeight,
        ];
    };

    const [text, colour, weight] = await label.evaluate(one);
    const iconRatio = icon ? (await icon.evaluate(one))[0] : null;

    return { text, icon: iconRatio, colour, weight };
}
