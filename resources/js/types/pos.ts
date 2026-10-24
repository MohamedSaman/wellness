import type { ThermalLine } from '@/lib/receipt/layout';

export type PosShop = {
    name: string;
    currency: string;
    decimals: number;
    requireOpenShift: boolean;
    quickCash: number[];
};

export type PosLocation = { id: number; code: string; name: string };

/**
 * The till the cashier is using. Every till is fixed to one location; it
 * sells from that location's stock. `location` and `opdBilling` are missing
 * only on pages rendered before branch 8.
 */
export type PosTill = {
    uuid: string;
    code: string;
    name: string;
    location?: PosLocation | null;
    /** The till rings up OPD bills (consultation plus one Medicines amount). */
    opdBilling?: boolean;
};

/** A till in the picker: only tills at locations the cashier may use. `location` is its name. */
export type PosTillOption = {
    uuid: string;
    code: string;
    name: string;
    shiftOpen: boolean;
    location?: string | null;
};

/** PosController::index `cashier`. */
export type PosCashier = {
    id: number;
    name: string;
    canAdmin: boolean;
    unlimitedDiscount: boolean;
    /** pos.price_override: may type the price of an allow_price_override item. */
    canOverridePrice?: boolean;
    /** pos.batch_override: may choose a batch instead of first expiry first out. */
    canBatchOverride?: boolean;
    /** pos.opd_bill: may ring up OPD bills. */
    canOpdBill?: boolean;
    /** stock.request with multi_location, at a till that is not where stock is received: may ask for stock. */
    canRequestStock?: boolean;
    /** May give a discount: no limit (pos.discount.unlimited) or the shop's cashier limit above 0. */
    canDiscount?: boolean;
};

export type PosFeatures = { vehicleFitment: boolean; pharmacy?: boolean };

export type RxPrescriberRule = 'required' | 'prompt';

export type OpdReceiptMedicines = 'names' | 'totals';

/** PosController::index `pharmacy`: null when the module is off. */
export type PosPharmacy = {
    /** 'required': an Rx line can't be paid for without a prescriber; 'prompt': the till asks. */
    rxPrescriber: RxPrescriberRule;
    /** The clinic doctor, pre-filled as the prescriber. */
    defaultPrescriber: string | null;
    /** Controlled lines need the patient's name, and can't be sold offline. */
    controlledRegister: boolean;
    bankTransferReferenceRequired: boolean;
    opdReceiptMedicines: OpdReceiptMedicines;
    /** OPD bills: warn below this % of the medicines' list value; 0 = never. */
    opdBelowListWarningPercent?: number;
    /** The consultation item presented for this till; null if unset or not sellable here. */
    opdConsultation: PosItem | null;
} | null;

export type BillType = 'standard' | 'opd';

export type PosShift = {
    uuid: string;
    opened_at: string;
    opening_cash: number;
    opened_by: string;
};

/** An item as returned by the search endpoint. Prices are minor units; there is never a cost. */
export type PosItem = {
    variant_id: number;
    name: string;
    variant_name: string | null;
    sku: string;
    barcode: string | null;
    part_number: string | null;
    oem_numbers: string[];
    unit: string;
    price: number | null;
    track_stock: boolean;
    stock: number | null;
    /** The shop's tax rate applies to standard items only. Missing on items cached before tax categories: standard. */
    tax_category?: TaxCategory;
    /** Medicines only (pharmacy shops); null for anything else. */
    drug?: DrugInfo | null;
    /*
     * Branch 8 keys below. All optional: items cached by an older catalog
     * lack them, and are treated as one base unit (no unit_id sent), no
     * batches, sellable when priced.
     */
    /** Active units, base first. Missing = only the base unit (`unit`, `price`). */
    units?: PosUnit[];
    /** Some unit sells at this till and has a price. */
    sellable?: boolean;
    whole_units_only?: boolean;
    track_batches?: boolean;
    /** The till may type this item's price (non-stock products only). */
    allow_price_override?: boolean;
    /** The medicine's own "stop selling days before expiry"; null = the shop setting. */
    min_days_to_expiry?: number | null;
    /** Batch-tracked items: batches at this till's location, first expiry first. */
    batches?: PosBatch[];
    nearest_expiry?: string | null;
    /** Exact match on a pack's barcode: that pack. Null otherwise. */
    scanned_unit_id?: number | null;
};

/** A unit an item is sold in: the base unit (factor 1) or a pack of base units. */
export type PosUnit = {
    id: number;
    name: string;
    /** Base units in one of this unit. */
    factor: number;
    barcode: string | null;
    /** Minor units; null = no price on the default price list. */
    price: number | null;
    is_base: boolean;
    is_default_sale: boolean;
    /** Sold at this till's location (per-location loose sale rules applied). */
    sellable: boolean;
};

/** A batch at the till's location. Quantity in base units; expiry 'YYYY-MM-DD'. */
export type PosBatch = {
    id: number;
    batch_no: string;
    expiry: string | null;
    quantity: number;
};

/** A batch taken by a line, as the quote (or the till's own FEFO) plans it. Base units. */
export type QuoteBatch = {
    batch_id: number;
    batch_no: string;
    expiry: string | null;
    quantity: number;
};

export type TaxCategory = 'standard' | 'exempt';

export type DrugSchedule = 'otc' | 'prescription_only' | 'controlled';

export type DrugInfo = {
    generic_names: string[];
    brand_name: string | null;
    strength: string | null;
    dosage_form: string;
    schedule: DrugSchedule;
};

export type PaymentMethod = 'cash' | 'card' | 'bank_transfer';

export type PaymentRow = {
    key: string;
    method: PaymentMethod;
    /** What the cashier typed, e.g. "5000" or "1250.50". The server converts it. */
    amount: string;
    reference: string;
};

export type QuoteLine = {
    product_variant_id: number;
    description: string;
    sku: string;
    quantity: number;
    /** The price charged per unit. OPD medicine lines: the list price, or 0 (their total is a share). */
    unit_price: number;
    line_discount: number;
    /**
     * Null on an OPD bill's medicine lines, as are tax_amount and line_total:
     * a line's share of the Medicines amount is never sent to the till (an
     * unpriced medicine's share is weighed by its cost). The bill's
     * medicines_total and medicines_tax are on the totals.
     */
    sale_discount_share: number | null;
    tax_amount: number | null;
    tax_category: TaxCategory;
    line_total: number | null;
    /** Null only on the till's own quote for an item cached without units. */
    variant_unit_id?: number | null;
    unit_name?: string;
    unit_factor?: number;
    base_quantity?: number;
    /** The price list's unit price when the line was charged something else (override, OPD share). */
    list_price?: number | null;
    is_opd_medicine?: boolean;
    drug_schedule?: DrugSchedule | null;
    /** Batch-tracked lines (quote with a till): the batches FEFO would take; [] otherwise. */
    batches?: QuoteBatch[];
    /** Base units the batches can't cover. */
    shortfall?: number;
};

export type QuoteTotals = {
    subtotal: number;
    discount_total: number;
    tax_total: number;
    grand_total: number;
    /** OPD bills: Σ the medicine lines' line_total (the Medicines amount, with exclusive tax); null otherwise. */
    medicines_total: number | null;
    /** OPD bills: Σ the medicine lines' tax; null otherwise. */
    medicines_tax: number | null;
    /** OPD bills: the priced medicines at list price (Σ list price × quantity; never a cost); null otherwise. */
    medicines_list_value?: number | null;
    /** OPD bills: the typed amount is below the shop's warning share of the list value (warn, never block). */
    medicines_below_list?: boolean | null;
};

export type PaymentPreview = {
    paid_total: number;
    change_due: number;
    short_by: number;
    error: string | null;
};

export type Quote = {
    totals: QuoteTotals;
    lines: QuoteLine[];
    payment: PaymentPreview | null;
};

export type VehicleMake = {
    id: number;
    name: string;
    models: {
        id: number;
        name: string;
        year_from: number | null;
        year_to: number | null;
    }[];
};

export type CompletedSale = {
    uuid: string;
    invoice_no: string;
    grand_total: number;
    change_due: number;
    /** Null for a sale made offline: its receipt is printed from this browser. */
    receipt_url: string | null;
};

/** How the shop prints receipts (Receipt settings, branch 9); read when printing. */
export type ReceiptPrintOptions = {
    /** Millimetres: 80 (48 characters a line) or 58 (32). */
    paperWidth: number;
    showBatch: boolean;
    showExpiry: boolean;
    showPrescriber: boolean;
};

export type ReceiptShop = {
    name: string;
    address: string | null;
    phone: string | null;
    taxNumber: string | null;
    logoUrl: string | null;
    header: string | null;
    footer: string | null;
    currency: string;
    decimals: number;
    /** Missing from servers and catalogs before branch 9: 80 mm, batch and expiry printed, no prescriber. */
    receipt?: ReceiptPrintOptions;
};

/** A receipt laid out for thermal paper by the server (ReceiptService::thermal()). */
export type ThermalReceiptData = {
    paperWidth: number;
    columns: number;
    lines: ThermalLine[];
    /** The same receipt as ESC/POS bytes, base64. */
    escpos: string;
};

/** Set on a duplicate receipt: when and by whom it was reprinted (shop time). */
export type ReceiptReprint = { printedAt: string; printedBy: string };

/**
 * Money in minor units. `status`, `refundedTotal` and `returnedQuantity` are
 * missing for a sale not yet on the server; `reprint` is set only on a duplicate.
 */
export type ReceiptSale = {
    invoiceNo: string;
    status?: string;
    soldAt: string;
    cashier: string;
    /** The till and its location, as now (branch 9). */
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
    refundedTotal?: number;
    items: {
        description: string;
        sku: string;
        /** The medicine's strength when sold ("500 mg"). */
        strength?: string | null;
        /** What stock is counted in when sold ("Tablet"). */
        baseUnitName?: string | null;
        /** The location the line was sold from (bills from several locations are grouped). */
        location?: string | null;
        quantity: string;
        returnedQuantity?: string | null;
        returnedBaseQuantity?: string | null;
        /** Null, with discount and lineTotal, on an OPD bill's medicine lines: their shares are not printed. */
        unitPrice: number | null;
        discount: number | null;
        lineTotal: number | null;
        /** Marked "E" on the receipt when the sale was taxed. */
        taxExempt?: boolean;
        unitName?: string;
        /** A medicine on an OPD bill: printed under the one Medicines amount, without a price. */
        isOpdMedicine?: boolean;
        prescriber?: string | null;
        prescriptionRef?: string | null;
        /** Batch-tracked lines: batch number and expiry as sold (a snapshot). */
        batches?: {
            batchNo: string | null;
            expiry: string | null;
            quantity: string;
        }[];
    }[];
    payments: { method: string; amount: number; reference?: string | null }[];
    reprint?: ReceiptReprint | null;
    /** Missing on receipts from before branch 8: a standard bill. */
    billType?: BillType;
    /** OPD receipts: medicine names without prices, or totals only. */
    opdReceiptMedicines?: OpdReceiptMedicines;
    /** OPD bills: the Medicines amount (sum of the medicine lines); null otherwise. */
    medicinesTotal?: number | null;
};

/** A row of the recent sales panel (F6). */
export type RecentSale = {
    uuid: string;
    invoice_no: string;
    sold_at: string;
    cashier: string;
    grand_total: number;
    status: string;
};

/** StockRequestController: an item that can be asked for, with what each side holds (base units). */
export type StockRequestItemOption = {
    id: number;
    name: string;
    sku: string;
    baseUnit: string;
    units: { id: number; name: string; factor: number; isBase: boolean }[];
    onHandHere: number;
    onHandThere: number;
};

/** StockRequestController::index `requests`. */
export type StockRequestSummary = {
    uuid: string;
    number: string;
    status: 'requested' | 'partly_sent' | 'sent' | 'cancelled';
    statusLabel: string;
    requestedAt: string;
    requestedBy: string | null;
    for: string;
    from: string;
    note: string | null;
    canCancel: boolean;
    printUrl: string;
    items: {
        name: string;
        quantity: string;
        sent: string;
        outstanding: string;
        baseUnit: string;
    }[];
};
