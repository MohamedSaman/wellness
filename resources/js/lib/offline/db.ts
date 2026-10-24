import Dexie from 'dexie';
import type { EntityTable } from 'dexie';
import type {
    BillType,
    OpdReceiptMedicines,
    PaymentMethod,
    PosItem,
    ReceiptPrintOptions,
    RxPrescriberRule,
} from '@/types/pos';

/**
 * The till's own copy of what it needs to keep selling without the server,
 * kept in IndexedDB so it survives reloads and restarts.
 *
 * - items:  the catalog (selling price + stock, never cost), from /pos/sync/catalog
 * - outbox: sales made offline, uploaded to /pos/sync/sales in order
 * - meta:   shop settings, the till, its device token, invoice numbers, sync cursor
 */
export type Fitment = {
    make_id: number;
    model_id: number;
    year_from: number | null;
    year_to: number | null;
};

export type CatalogItem = PosItem & {
    product_id: number;
    is_active: true;
    fitments: Fitment[];
    /**
     * The variant's barcode and every unit's barcode, for the multi-entry
     * index (a scanned pack finds its item). Computed by the till when it
     * stores the item (withBarcodes()); never sent by the server.
     */
    barcodes?: string[];
};

/** Every barcode that finds the item: its own and its packs'. */
export function barcodesOf(item: PosItem): string[] {
    return [
        ...new Set(
            [item.barcode, ...(item.units ?? []).map((unit) => unit.barcode)]
                .filter((code): code is string => !!code)
                .map((code) => code.toLowerCase()),
        ),
    ];
}

export function withBarcodes(item: CatalogItem): CatalogItem {
    return { ...item, barcodes: barcodesOf(item) };
}

/** Shop settings the till needs to price, number and print a sale on its own. */
export type OfflineShop = {
    name: string;
    address: string | null;
    phone: string | null;
    taxNumber: string | null;
    logoUrl: string | null;
    header: string | null;
    footer: string | null;
    currency: string;
    decimals: number;
    timezone: string;
    taxEnabled: boolean;
    taxRate: number;
    pricesIncludeTax: boolean;
    maxCashierDiscountPercent: number;
    invoicePadding: number;
    requireOpenShift: boolean;
    quickCash: number[];
    /*
     * Branch 8 settings (CatalogSyncService::shopSettings()). Optional: a
     * catalog synced by an older server lacks them; read with the defaults
     * noted on each.
     */
    /** The pharmacy module is on. Default false. */
    pharmacy?: boolean;
    /** Default 'prompt'. */
    rxPrescriber?: RxPrescriberRule;
    defaultPrescriber?: string | null;
    /** Controlled lines are blocked offline. Default false. */
    controlledRegister?: boolean;
    /** Default 0. A medicine's own min_days_to_expiry wins. */
    minDaysToExpiryForSale?: number;
    /** Default 'names'. */
    opdReceiptMedicines?: OpdReceiptMedicines;
    opdConsultationVariantId?: number | null;
    /** Default false. */
    bankTransferReferenceRequired?: boolean;
    /** OPD bills (2026-10-22): warn below this % of the medicines' list value; 0 = never. Default 100. */
    opdBelowListWarningPercent?: number;
    /** Branch 9: how receipts print. Default 80 mm, batch and expiry printed, no prescriber. */
    receipt?: ReceiptPrintOptions;
};

/** The till the catalog was synced for. Location and OPD keys are missing on older catalogs. */
export type OfflineTill = {
    uuid: string;
    code: string;
    name: string;
    location_id?: number;
    location_code?: string;
    location_name?: string;
    opd_billing?: boolean;
};

/** Invoice numbers this till reserved while online, used one by one offline. */
export type InvoiceNumbers = {
    tillUuid: string;
    code: string;
    ranges: { first: number; last: number }[];
    /** The next unused number, or null before the first block arrives. */
    next: number | null;
};

/**
 * What POST /pos/sync/sales receives for one sale. Amounts are minor units.
 * Payload v2 adds the optional keys (OfflineSaleSyncService::rules()); outbox
 * rows written before them replay as before (base unit, the till's location,
 * server FEFO).
 */
export type SyncSalePayload = {
    uuid: string;
    invoice_no: string;
    sold_at: string;
    cashier_id: number;
    lines: {
        variant_id: number;
        /** In the unit sold (unit_id, else the base unit). */
        quantity: number;
        /** Per unit sold. The typed price when price_override; ignored on OPD medicine lines. */
        unit_price: number;
        line_discount: number;
        unit_id?: number | null;
        location_id?: number | null;
        /** The till's own FEFO proposal (or the cashier's pick), base units. */
        batches?: { batch_id: number; quantity: number }[];
        prescriber?: string | null;
        prescription_ref?: string | null;
        /** unit_price is a price typed at the till. */
        price_override?: boolean;
    }[];
    sale_discount: number;
    payments: {
        method: PaymentMethod;
        amount: number;
        reference: string | null;
    }[];
    grand_total: number;
    tax_rate: number;
    prices_include_tax: boolean;
    bill_type?: BillType;
    /** OPD bills: the typed Medicines amount. */
    medicines_amount?: number | null;
    customer_name?: string | null;
    customer_phone?: string | null;
};

/** Everything a printed receipt needs, captured when the sale was made. */
export type OfflineReceipt = {
    invoiceNo: string;
    soldAt: string;
    cashier: string;
    /** Branch 9: the till and its location, printed in the header. */
    till?: {
        code: string;
        name: string | null;
        location: string | null;
    } | null;
    subtotal: number;
    discountTotal: number;
    taxTotal: number;
    taxRate: number;
    pricesIncludeTax: boolean;
    grandTotal: number;
    paidTotal: number;
    changeDue: number;
    items: {
        description: string;
        sku: string;
        quantity: string;
        /** Null, with discount and lineTotal, on an OPD bill's medicine lines (as the server's receipt). */
        unitPrice: number | null;
        discount: number | null;
        lineTotal: number | null;
        taxExempt?: boolean;
        unitName?: string;
        /** Branch 9: the medicine's strength and what stock is counted in. */
        strength?: string | null;
        baseUnitName?: string | null;
        isOpdMedicine?: boolean;
        prescriber?: string | null;
        prescriptionRef?: string | null;
        /** The till's own FEFO proposal: the server may record other batches. */
        batches?: {
            batchNo: string | null;
            expiry: string | null;
            quantity: string;
        }[];
    }[];
    payments: { method: string; amount: number; reference?: string | null }[];
    billType?: BillType;
    opdReceiptMedicines?: OpdReceiptMedicines;
    medicinesTotal?: number | null;
};

export type OutboxStatus = 'pending' | 'synced' | 'rejected';

export type OutboxSale = {
    seq?: number;
    uuid: string;
    tillUuid: string;
    status: OutboxStatus;
    payload: SyncSalePayload;
    receipt: OfflineReceipt;
    createdAt: string;
    attempts: number;
    /** Epoch ms: do not retry before this (backoff). */
    nextAttemptAt: number;
    lastError: string | null;
    syncedAt: string | null;
    /** The server's invoice number, if it had to renumber the sale. */
    serverInvoiceNo: string | null;
    needsReview: boolean;
};

export type MetaRow = { key: string; value: unknown };

export type OfflineDb = Dexie & {
    items: EntityTable<CatalogItem, 'variant_id'>;
    outbox: EntityTable<OutboxSale, 'seq'>;
    meta: EntityTable<MetaRow, 'key'>;
};

export const db = new Dexie('pos-offline') as OfflineDb;

db.version(1).stores({
    items: '&variant_id, barcode, sku',
    outbox: '++seq, &uuid, status, [tillUuid+status]',
    meta: '&key',
});

// v2: items are also found by any of their barcodes (variant + packs). The
// outbox is unchanged, so sales waiting from v1 replay as they were written.
db.version(2)
    .stores({
        items: '&variant_id, barcode, sku, *barcodes',
        outbox: '++seq, &uuid, status, [tillUuid+status]',
        meta: '&key',
    })
    .upgrade((tx) =>
        tx
            .table<CatalogItem, number>('items')
            .toCollection()
            .modify((item) => {
                item.barcodes = barcodesOf(item);
            }),
    );

export async function getMeta<T>(key: string): Promise<T | null> {
    return ((await db.meta.get(key))?.value as T | undefined) ?? null;
}

export async function setMeta(key: string, value: unknown): Promise<void> {
    await db.meta.put({ key, value });
}

export const metaKeys = {
    shop: 'shop',
    till: 'till',
    vehicles: 'vehicles',
    cursor: 'catalogCursor',
    catalogTill: 'catalogTill',
    /** The till's location the catalog's stock and batches are for. */
    catalogLocation: 'catalogLocation',
    lastFullSync: 'lastFullSync',
    lastCatalogSync: 'lastCatalogSync',
    invoiceNumbers: 'invoiceNumbers',
    token: (tillUuid: string) => `token:${tillUuid}`,
} as const;
