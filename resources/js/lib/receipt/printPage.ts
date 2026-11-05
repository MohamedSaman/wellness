/**
 * Prints a thermal receipt in the browser: the one way a pharmacy's receipt
 * reaches Chrome, online (pages/pos/Receipt.vue) and offline (pages/pos/Index.vue).
 *
 * The page is sized to the receipt because Chrome ignores `size: 80mm auto`
 * (it drops the whole declaration): the height is measured here. `margin: 0`
 * leaves Chrome no room for its header and footer (the page title and URL).
 * A receipt longer than 297 mm, the driver's usual longest roll page, runs on
 * to a second page instead of being shrunk to fit one.
 */
const RECEIPT_FONT = 'POS Receipt Mono';
const MM_PER_PX = 25.4 / 96;
const MAX_PAGE_MM = 297;
/** The measured height can round down; a page too short spills one line onto a second. */
const SLACK_MM = 2;

/** Fetches the receipt's font, so it is at hand when the till is offline and for the print below. */
export async function loadReceiptFont(): Promise<void> {
    await Promise.all([
        document.fonts.load(`400 12px "${RECEIPT_FONT}"`),
        document.fonts.load(`700 12px "${RECEIPT_FONT}"`),
    ]).catch(() => undefined);
}

export function pageHeightMm(receiptHeightPx: number): number {
    return Math.min(
        Math.ceil(receiptHeightPx * MM_PER_PX) + SLACK_MM,
        MAX_PAGE_MM,
    );
}

/** @param receipt the receipt element, already on the page. */
export async function printReceiptPage({
    receipt,
    paperWidth,
    title,
}: {
    receipt: HTMLElement;
    paperWidth: number;
    title: string;
}): Promise<void> {
    await loadReceiptFont();
    await Promise.all(
        [...receipt.querySelectorAll('img')].map((image) =>
            image.decode().catch(() => undefined),
        ),
    );

    const height = pageHeightMm(receipt.getBoundingClientRect().height);
    const page = document.createElement('style');
    page.textContent = `@page { size: ${paperWidth}mm ${height}mm; margin: 0; }`;
    document.head.appendChild(page);

    const pageTitle = document.title;
    document.title = title;
    document.body.classList.add('printing-thermal');

    window.addEventListener(
        'afterprint',
        () => {
            page.remove();
            document.title = pageTitle;
            document.body.classList.remove('printing-thermal');
        },
        { once: true },
    );

    window.print();
}
