import CashMovementController from '@/actions/App/Http/Controllers/Pos/CashMovementController';
import ExchangeController from '@/actions/App/Http/Controllers/Pos/ExchangeController';
import ReprintController from '@/actions/App/Http/Controllers/Pos/ReprintController';
import ShiftReprintController from '@/actions/App/Http/Controllers/Pos/ShiftReprintController';
import { postJson } from '@/lib/http';
import type { CashMovementRow } from '@/types/pos';

/**
 * Reprint a sale from the till (Recent sales, Patients this shift). The
 * reprint is logged first; the server then hands back the duplicate
 * receipt page, which prints itself. Call it straight from the click: the
 * window opens at once, so the browser does not block it as a pop-up.
 * Online only, since every reprint is logged by the server.
 */
export async function reprintSale(
    saleUuid: string,
    tillUuid: string,
): Promise<void> {
    const receiptWindow = window.open('', '_blank', 'width=420,height=720');

    try {
        const response = await postJson<{ receipt_url: string }>(
            ReprintController.store.url({ sale: saleUuid }),
            { device: tillUuid },
        );
        const url = `${response.receipt_url}?print=1`;

        if (receiptWindow) {
            receiptWindow.location.href = url;
        } else {
            window.open(url, '_blank', 'width=420,height=720');
        }
    } catch (caught) {
        receiptWindow?.close();

        throw caught;
    }
}

/**
 * Reprint a closed shift's summary from the till (Shift summaries, branch
 * 17): logged by the server first, then its receipt page opens, prints
 * itself marked REPRINT and closes. As reprintSale(): call it straight
 * from the click, and online only.
 */
export async function reprintShiftSummary(
    shiftUuid: string,
    tillUuid: string,
): Promise<void> {
    const summaryWindow = window.open('', '_blank', 'width=420,height=720');

    try {
        const response = await postJson<{ summary_url: string }>(
            ShiftReprintController.store.url({ shift: shiftUuid }),
            { device: tillUuid, format: 'thermal' },
        );
        const url = `${response.summary_url}?print=1`;

        if (summaryWindow) {
            summaryWindow.location.href = url;
        } else {
            window.open(url, '_blank', 'width=420,height=720');
        }
    } catch (caught) {
        summaryWindow?.close();

        throw caught;
    }
}

/**
 * Record a cash movement (paid out, paid in, a doctor payment; branch 18)
 * and print its slip. As reprintSale(): call it straight from the click,
 * so the slip's window opens at once and is not blocked as a pop-up; it is
 * closed again if the server refuses the movement.
 */
export async function printCashMovement(body: {
    device: string;
    type: string;
    amount: string;
    category?: string | null;
    doctor?: number | null;
    note?: string | null;
}): Promise<CashMovementRow> {
    const slipWindow = window.open('', '_blank', 'width=420,height=720');

    try {
        const response = await postJson<{
            movement: CashMovementRow;
            slip_url: string;
        }>(CashMovementController.store.url(), body);
        const url = `${response.slip_url}?print=1`;

        if (slipWindow) {
            slipWindow.location.href = url;
        } else {
            window.open(url, '_blank', 'width=420,height=720');
        }

        return response.movement;
    } catch (caught) {
        slipWindow?.close();

        throw caught;
    }
}

/** Reprint a cash movement's slip: logged first, then printed marked REPRINT. */
export async function reprintCashMovement(
    movementUuid: string,
    tillUuid: string,
): Promise<void> {
    const slipWindow = window.open('', '_blank', 'width=420,height=720');

    try {
        const response = await postJson<{ slip_url: string }>(
            CashMovementController.reprint.url({ cashMovement: movementUuid }),
            { device: tillUuid },
        );
        const url = `${response.slip_url}?print=1`;

        if (slipWindow) {
            slipWindow.location.href = url;
        } else {
            window.open(url, '_blank', 'width=420,height=720');
        }
    } catch (caught) {
        slipWindow?.close();

        throw caught;
    }
}

/** Reprint a return or exchange's receipt: logged first, then printed marked REPRINT. */
export async function reprintExchange(
    exchangeUuid: string,
    tillUuid: string,
): Promise<void> {
    const receiptWindow = window.open('', '_blank', 'width=420,height=720');

    try {
        const response = await postJson<{ receipt_url: string }>(
            ExchangeController.reprint.url({ exchange: exchangeUuid }),
            { device: tillUuid },
        );
        const url = `${response.receipt_url}?print=1`;

        if (receiptWindow) {
            receiptWindow.location.href = url;
        } else {
            window.open(url, '_blank', 'width=420,height=720');
        }
    } catch (caught) {
        receiptWindow?.close();

        throw caught;
    }
}
