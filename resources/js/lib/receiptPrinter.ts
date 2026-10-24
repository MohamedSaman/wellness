/**
 * Sends a receipt to a printer. Every receipt (first print, reprint, refund
 * and void slips, offline receipts) goes through here.
 *
 * - The browser prints the receipt shown on the page (window.print()): the
 *   thermal layout in a monospaced font at the paper's width, so a receipt
 *   printer set up in the operating system prints it as it is.
 * - A till with QZ Tray (https://qz.io) and a receipt printer named in this
 *   browser (localStorage "pos.receiptPrinter") sends the ESC/POS bytes
 *   straight to the printer instead. QZ Tray's script (window.qz) is added
 *   when a till is set up with it (clinic deployment); without it, or if it
 *   fails, the browser prints.
 */
export type ReceiptJob = {
    /** ESC/POS bytes, base64; null = only the page can be printed. */
    escpos: string | null;
    /** How the browser prints it; default window.print(). */
    browserPrint?: () => void;
};

export interface ReceiptPrinter {
    print(job: ReceiptJob): Promise<void>;
}

/** The part of QZ Tray's API used here. */
type QzTray = {
    websocket: { isActive(): boolean; connect(): Promise<void> };
    configs: { create(printer: string): unknown };
    print(
        config: unknown,
        data: {
            type: 'raw';
            format: 'command';
            flavor: 'base64';
            data: string;
        }[],
    ): Promise<void>;
};

const PRINTER_KEY = 'pos.receiptPrinter';

/** Prints the receipt rendered on the current page with the browser's print dialog. */
export const browserPrinter: ReceiptPrinter = {
    print: (job) => {
        (job.browserPrint ?? (() => window.print()))();

        return Promise.resolve();
    },
};

function qzTray(): QzTray | null {
    return (window as unknown as { qz?: QzTray }).qz ?? null;
}

function printerName(): string | null {
    try {
        return window.localStorage.getItem(PRINTER_KEY) || null;
    } catch {
        return null;
    }
}

/** ESC/POS through QZ Tray to the named printer; the browser if that fails. */
export const qzPrinter: ReceiptPrinter = {
    async print(job) {
        const qz = qzTray();
        const printer = printerName();

        if (qz === null || printer === null || job.escpos === null) {
            return browserPrinter.print(job);
        }

        try {
            if (!qz.websocket.isActive()) {
                await qz.websocket.connect();
            }

            await qz.print(qz.configs.create(printer), [
                {
                    type: 'raw',
                    format: 'command',
                    flavor: 'base64',
                    data: job.escpos,
                },
            ]);
        } catch {
            await browserPrinter.print(job);
        }
    },
};

export function receiptPrinter(): ReceiptPrinter {
    return qzTray() !== null && printerName() !== null
        ? qzPrinter
        : browserPrinter;
}
