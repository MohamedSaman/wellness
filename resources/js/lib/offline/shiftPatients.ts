import { db } from './db';

/** A bill with a patient this till made offline in its open shift, as its receipt printed it. */
export type OfflineShiftBill = {
    uuid: string;
    invoiceNo: string;
    /** Till time, "2026-10-18 00:10". */
    soldAt: string;
    patient: string;
    age: string | null;
    /** Still waiting to reach the server (or turned down). */
    unsynced: boolean;
};

/**
 * Patients this shift, offline (docs/PHARMACY.md, branch 16): the sales in
 * this browser's outbox for this till since the shift opened that name a
 * patient, oldest first. The outbox holds only the sales made offline (kept
 * a week once synced), so bills rung up online are not here.
 */
export async function offlineShiftBills(
    tillUuid: string,
    shiftOpenedAt: string,
): Promise<OfflineShiftBill[]> {
    const openedAt = Date.parse(shiftOpenedAt);
    const sales = await db.outbox
        .filter(
            (sale) =>
                sale.tillUuid === tillUuid &&
                Date.parse(sale.payload.sold_at) >= openedAt &&
                Boolean(sale.payload.patient),
        )
        .toArray();

    return sales
        .sort((a, b) => a.payload.sold_at.localeCompare(b.payload.sold_at))
        .map((sale) => ({
            uuid: sale.uuid,
            invoiceNo: sale.serverInvoiceNo ?? sale.receipt.invoiceNo,
            soldAt: sale.receipt.soldAt,
            patient:
                sale.receipt.patient?.name ?? sale.payload.patient?.name ?? '',
            age: sale.receipt.patient?.age ?? null,
            unsynced: sale.status !== 'synced',
        }));
}
