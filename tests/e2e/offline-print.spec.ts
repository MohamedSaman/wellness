import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import {
    expectReady,
    goOffline,
    offlineMeta,
    resetTill,
    sellOffline,
    useTill,
} from './helpers';

/**
 * What Chrome prints for a pharmacy's receipt (docs/deploy/TILLS.md): the same
 * sale printed offline by the till and online by the server is the same
 * paper. Printed to PDF with Chrome's header and footer switched on, as a
 * till with the wrong print settings would, and read back with poppler-utils.
 * Chromium only: Playwright has no PDF in Firefox.
 */

test.use({ viewport: { width: 1280, height: 800 } });

const MM_PER_POINT = 25.4 / 72;
const PRINTABLE_PX = (72 / 25.4) * 96;

type PrintLog = { printCalls: number; printTitle: string };

/**
 * window.print does nothing in a test: it records the print job's title, and
 * the test prints to PDF at that moment. (Printing to PDF fires afterprint,
 * so the page is back to normal once it has.)
 */
async function stubPrint(page: Page): Promise<void> {
    await page.addInitScript(() => {
        const printed = window as unknown as PrintLog;
        printed.printCalls = 0;
        window.print = () => {
            printed.printCalls += 1;
            printed.printTitle = document.title;
        };
    });
}

function reach(bbox: string): { leftMm: number; rightMm: number } {
    const edges = [
        ...bbox.matchAll(/<word xMin="([\d.]+)".*? xMax="([\d.]+)"/g),
    ];

    return {
        leftMm: Math.min(...edges.map((m) => Number(m[1]))) * MM_PER_POINT,
        rightMm: Math.max(...edges.map((m) => Number(m[2]))) * MM_PER_POINT,
    };
}

async function printed(page: Page, file: string) {
    await expect(async () => {
        expect(
            await page.evaluate(
                () => (window as unknown as PrintLog).printCalls,
            ),
        ).toBeGreaterThan(0);
    }).toPass({ timeout: 10_000 });

    await page.pdf({
        path: file,
        preferCSSPageSize: true,
        displayHeaderFooter: true,
        printBackground: true,
    });

    const info = execFileSync('pdfinfo', [file]).toString();
    const size = /Page size:\s+([\d.]+) x ([\d.]+) pts/.exec(info);

    return {
        pages: Number(/Pages:\s+(\d+)/.exec(info)?.[1]),
        widthMm: Number(size?.[1]) * MM_PER_POINT,
        heightMm: Number(size?.[2]) * MM_PER_POINT,
        text: execFileSync('pdftotext', ['-layout', file, '-'])
            .toString()
            .trim(),
        fonts: execFileSync('pdffonts', [file]).toString(),
        // How far the words reach across the paper, in mm from its left edge.
        ...reach(execFileSync('pdftotext', ['-bbox', file, '-']).toString()),
        images: execFileSync('pdfimages', ['-list', file]).toString(),
    };
}

/** Runs in the page: what the thermal receipt looks like, and whether its logo is pure black and white. */
async function look(page: Page) {
    return page.locator('.thermal-receipt').evaluate(async (receipt) => {
        const lines = receipt.querySelector<HTMLElement>('.thermal-lines');
        const logo = receipt.querySelector('img');
        let greys = -1;

        if (logo) {
            await logo.decode();
            const canvas = document.createElement('canvas');
            canvas.width = logo.naturalWidth;
            canvas.height = logo.naturalHeight;
            const context = canvas.getContext('2d');
            context?.drawImage(logo, 0, 0);
            const data = context?.getImageData(
                0,
                0,
                canvas.width,
                canvas.height,
            ).data;
            greys = 0;

            for (let i = 0; i < (data?.length ?? 0); i += 4) {
                greys += data?.[i] === 0 || data?.[i] === 255 ? 0 : 1;
            }
        }

        const inside = lines ?? receipt;
        const style = getComputedStyle(inside);

        return {
            fontPx: parseFloat(style.fontSize),
            colour: style.color,
            family: style.fontFamily,
            width: inside.getBoundingClientRect().width,
            overflows: inside.scrollWidth > inside.clientWidth,
            logoDots: logo?.naturalWidth ?? 0,
            logoSource: logo?.getAttribute('src')?.slice(0, 22) ?? '',
            greys,
        };
    });
}

test('a receipt printed offline is the same paper as the one printed online', async ({
    page,
    context,
    browserName,
}, info) => {
    test.skip(browserName !== 'chromium', 'Playwright prints PDF in Chromium');

    await stubPrint(page);
    await resetTill(context, 'T1');
    await page.goto('/pos');
    await useTill(page, 'T1');
    await expectReady(page);
    await expect(async () => {
        expect((await offlineMeta(page)).keys).toContain('receiptLogo');
    }).toPass({ timeout: 30_000 });

    await goOffline(page, context);
    const invoice = await sellOffline(page);

    const offlineFile = info.outputPath('offline.pdf');
    await page.locator('[data-test=print-receipt]').click();
    const offline = await printed(page, offlineFile);

    await test.step('Only the receipt, on 80 mm paper', async () => {
        expect(
            await page.evaluate(
                () => (window as unknown as PrintLog).printTitle,
            ),
        ).toBe(`Receipt ${invoice}`);
        expect(offline.pages).toBe(1);
        expect(offline.widthMm).toBeCloseTo(80, 0);
        expect(offline.heightMm).toBeGreaterThan(40);
        expect(offline.heightMm).toBeLessThan(297);
        expect(offline.text).toContain(invoice);
        expect(offline.text).toContain('TOTAL');
    });

    await test.step("No trace of the till page or Chrome's header and footer", async () => {
        for (const forbidden of [
            '127.0.0.1',
            'http',
            'Checkout',
            'Current sale',
            'Sale complete',
            'Sign out',
            'Webxkey',
        ]) {
            expect(offline.text).not.toContain(forbidden);
        }
    });

    const offlineLook = await look(page);

    await test.step('Black, 12 px or more, inside the 72 mm the head reaches', async () => {
        expect(offlineLook.fontPx).toBeGreaterThanOrEqual(12);
        expect(offlineLook.colour).toBe('rgb(0, 0, 0)');
        expect(offlineLook.family).toContain('POS Receipt Mono');
        expect(offlineLook.width).toBeLessThanOrEqual(PRINTABLE_PX + 0.5);
        // On the paper itself: inside the 4 mm margins of the 80 mm roll.
        expect(offline.leftMm).toBeGreaterThanOrEqual(3.9);
        expect(offline.rightMm).toBeLessThanOrEqual(76.1);
        expect(offlineLook.overflows).toBe(false);
        expect(offline.fonts).toContain('LiberationMono');
        expect(offlineLook.logoSource).toContain('data:image/png');
        expect(offlineLook.greys).toBe(0);
        expect(offlineLook.logoDots).toBeGreaterThan(0);
        expect(offline.images).toContain('image');
    });

    await test.step('After printing the till is itself again', async () => {
        expect(await page.title()).toContain('Checkout');
        await expect(page.locator('body')).not.toHaveClass(/printing-thermal/);
        await page.locator('[data-test=new-sale]').click();
    });

    await context.setOffline(false);
    await expect(page.locator('[data-test=connection-badge]')).toHaveText(
        /^\s*Online\s*$/,
        { timeout: 60_000 },
    );

    const { lastSaleUuid } = await offlineMeta(page);
    const receipt = await context.newPage();

    await stubPrint(receipt);
    await receipt.goto(`/pos/receipts/${lastSaleUuid}`);
    await receipt.locator('[data-test=print-button]').click();
    const online = await printed(receipt, info.outputPath('online.pdf'));

    await test.step('The server prints the same text on the same paper', async () => {
        expect(online.text).toBe(offline.text);
        expect(online.pages).toBe(offline.pages);
        expect(online.widthMm).toBeCloseTo(offline.widthMm, 1);
        expect(online.heightMm).toBeCloseTo(offline.heightMm, 0);
        expect(await look(receipt)).toEqual(offlineLook);
    });

    await receipt.close();
});
