import { computed, reactive, watch } from 'vue';
import QuoteController from '@/actions/App/Http/Controllers/Pos/QuoteController';
import { useOfflinePos } from '@/composables/useOfflinePos';
import { ApiError, postJson } from '@/lib/http';
import { formatQuantity } from '@/lib/money';
import type {
    OfflineReceipt,
    OfflineShop,
    SyncSalePayload,
} from '@/lib/offline/db';
import { planLine, saleDay, toProposal } from '@/lib/offline/fefo';
import type { BatchPlan } from '@/lib/offline/fefo';
import {
    OfflinePricingError,
    checkPrescribers,
    isRxSchedule,
    previewPaymentsOffline,
    quoteOffline,
    toMinor,
    unitsOf,
} from '@/lib/offline/pricing';
import type { OfflineQuote, TillUnit } from '@/lib/offline/pricing';
import { itemsById } from '@/lib/offline/search';
import type {
    BillType,
    DrugInfo,
    PaymentMethod,
    PaymentRow,
    PosBatch,
    PosItem,
    PosUnit,
    Quote,
    RxPrescriberRule,
} from '@/types/pos';

/**
 * The single source of truth for the sale in progress.
 *
 * Everything in `state` is plain JSON, so offline mode can later save it to
 * IndexedDB with `snapshot()` and bring it back with `restore()`. The cart
 * holds only what the cashier chose (items, units, quantities, discounts,
 * payments, batch picks, prescriptions, a typed price where allowed); every
 * price and total comes from the server's `quote`. While the till is offline
 * the quote comes from the local catalog instead (same maths, local FEFO).
 *
 * A line is one item in one unit: a box and loose tablets of the same
 * medicine are two lines. An item with only its base unit is one line, as
 * it always was (unitId null, no unit_id sent).
 */
export type CartLineState = {
    /** `${variantId}:${unitId ?? 'base'}`. */
    key: string;
    variantId: number;
    name: string;
    variantName: string | null;
    sku: string;
    /** Null = the base unit. */
    unitId: number | null;
    /** The unit sold's name, e.g. "Tablet" or "Box of 30". */
    unit: string;
    /** Base units in one `unit`. */
    factor: number;
    /** The item's units at this till (choose only `sellable` ones); [] = the base unit only. */
    units: PosUnit[];
    quantity: number;
    /** Typed by the cashier, e.g. "50" or "12.50". The server reads it. */
    lineDiscount: string;
    /** Base units at this till when the item was added; null when not tracked. */
    stock: number | null;
    trackStock: boolean;
    trackBatches: boolean;
    wholeUnitsOnly: boolean;
    /** The item's price may be typed at the till (with cashier.canOverridePrice). */
    allowPriceOverride: boolean;
    /** A typed unit price, like lineDiscount; '' = the price list's. Sent as lines.*.price. */
    price: string;
    /** The price list's price of one `unit` when added (to pre-fill the price box); null = none. */
    listPrice: number | null;
    drug: DrugInfo | null;
    /** The item's batches at this till when added, FEFO order (for the batch picker). */
    batches: PosBatch[];
    minDaysToExpiry: number | null;
    /** A batch the cashier chose for the whole line (pos.batch_override); null = FEFO. */
    batchId: number | null;
    /** Null = the sale's prescription (state.prescription) on an Rx line; a string = this line's own. */
    prescriber: string | null;
    prescriptionRef: string | null;
};

export type CartState = {
    /** Generated when the cart starts. Sent with the sale so a retry never records it twice. */
    uuid: string;
    lines: CartLineState[];
    saleDiscount: string;
    payments: PaymentRow[];
    quote: Quote | null;
    quoteError: string | null;
    /** True while the shown quote does not match the current cart. */
    stale: boolean;
    /** Where the shown quote came from. */
    quoteSource: 'server' | 'offline';
    /** 'opd' on an OPD till by default: consultation + one Medicines amount. */
    billType: BillType;
    /** OPD bills: the Medicines amount as typed, e.g. "1500"; '' = not entered yet. */
    medicinesAmount: string;
    /** The sale's prescription, applied to every Rx line that has none of its own. */
    prescription: { prescriber: string; ref: string };
    /** The patient (controlled drugs with the register on). */
    customerName: string;
    customerPhone: string;
};

/** What the till is (set by the checkout page through configure()); not part of the sale. */
export type CartContext = {
    /** The till's uuid: sent with the quote so it is priced and allocated for its location. */
    deviceUuid: string | null;
    /** The till's location; offline lines are recorded there. */
    locationId: number | null;
    pharmacy: boolean;
    /** The till takes OPD bills and the cashier may ring them up: new carts start as OPD bills. */
    opdBilling: boolean;
    defaultPrescriber: string | null;
    rxPrescriber: RxPrescriberRule;
    /** The consultation item, pre-added to every new OPD bill. */
    opdConsultation: PosItem | null;
};

type SavedCart = Pick<
    CartState,
    | 'uuid'
    | 'lines'
    | 'saleDiscount'
    | 'payments'
    | 'billType'
    | 'medicinesAmount'
    | 'prescription'
    | 'customerName'
    | 'customerPhone'
>;

const newUuid = () => crypto.randomUUID();

const context = reactive<CartContext>({
    deviceUuid: null,
    locationId: null,
    pharmacy: false,
    opdBilling: false,
    defaultPrescriber: null,
    rxPrescriber: 'prompt',
    opdConsultation: null,
});

const state = reactive<CartState>({
    uuid: newUuid(),
    lines: [],
    saleDiscount: '',
    payments: [],
    quote: null,
    quoteError: null,
    stale: false,
    quoteSource: 'server',
    billType: 'standard',
    medicinesAmount: '',
    prescription: { prescriber: '', ref: '' },
    customerName: '',
    customerPhone: '',
});

const offline = useOfflinePos();

let sequence = 0;
let timer: ReturnType<typeof setTimeout> | undefined;
let watching = false;

function roundQuantity(value: number): number {
    return Math.round(value * 1000) / 1000;
}

export function lineKey(variantId: number, unitId: number | null): string {
    return `${variantId}:${unitId ?? 'base'}`;
}

/** The line's quantity in base units (what stock and batches count). */
export function baseQuantity(line: CartLineState): number {
    return roundQuantity(line.quantity * line.factor);
}

/** A prescription-only or controlled medicine. */
export function isRxLine(line: CartLineState): boolean {
    return isRxSchedule(line.drug?.schedule);
}

/** On an OPD bill a stock item is a medicine: no price of its own, a share of the Medicines amount. */
export function isOpdMedicineLine(line: CartLineState): boolean {
    return state.billType === 'opd' && line.trackStock;
}

/** The prescriber sent for the line: its own, else the sale's on an Rx line. */
export function prescriberFor(line: CartLineState): string | null {
    const value =
        line.prescriber ??
        (isRxLine(line) ? state.prescription.prescriber : '');

    return value.trim() === '' ? null : value.trim();
}

export function prescriptionRefFor(line: CartLineState): string | null {
    const value =
        line.prescriptionRef ?? (isRxLine(line) ? state.prescription.ref : '');

    return value.trim() === '' ? null : value.trim();
}

/**
 * The unit a new line starts in (§8): the pack scanned, else the default
 * sale unit if it sells here, else the base unit if it does, else the first
 * pack that does. Null when nothing sells at this till.
 */
export function defaultUnit(
    item: PosItem,
    unitId?: number | null,
): TillUnit | null {
    const units = unitsOf(item);
    const wanted = unitId ?? item.scanned_unit_id ?? null;

    if (wanted !== null) {
        return (
            units.find((unit) => unit.id === wanted && unit.sellable) ?? null
        );
    }

    const sellable = units.filter((unit) => unit.sellable);

    return (
        sellable.find((unit) => unit.is_default_sale) ??
        sellable.find((unit) => unit.is_base) ??
        sellable[0] ??
        null
    );
}

async function refreshQuote(): Promise<void> {
    const mine = ++sequence;

    if (state.lines.length === 0) {
        state.quote = null;
        state.quoteError = null;
        state.stale = false;

        return;
    }

    if (offline.isOffline.value && offline.canSellOffline.value) {
        await applyOfflineQuote(mine);

        return;
    }

    try {
        const result = await postJson<Quote>(
            QuoteController.url(),
            requestBody(),
        );

        offline.reportConnection(true);

        if (mine === sequence) {
            state.quote = result;
            state.quoteError = null;
            state.stale = false;
            state.quoteSource = 'server';
        }
    } catch (error) {
        if (error instanceof ApiError && error.status === 0) {
            offline.reportConnection(false);

            if (offline.canSellOffline.value) {
                await applyOfflineQuote(mine);

                return;
            }
        }

        if (mine === sequence) {
            state.quoteError =
                error instanceof ApiError
                    ? error.message
                    : 'Could not price the cart.';
            state.stale = true;
        }
    }
}

type LocalPricing = {
    shop: OfflineShop;
    quote: Quote;
    priced: OfflineQuote;
    lines: OfflineQuote['lines'];
    /** Keyed like state.lines: the till's FEFO proposal. */
    plans: BatchPlan[];
    taxRate: number;
    pricesIncludeTax: boolean;
    saleDiscount: number;
    medicinesAmount: number | null;
    opdReceiptMedicines: 'names' | 'totals';
    payments: {
        method: PaymentMethod;
        amount: number;
        reference: string | null;
    }[];
    paymentReferenceRequired: boolean;
    locationId: number | null;
};

/**
 * Prices the cart from the local catalog, exactly as the server would, and
 * plans its batches. `isQuote`: for the screen (an OPD bill's Medicines
 * amount may still be blank), not for the sale.
 */
async function priceLocally(
    at: Date = new Date(),
    isQuote = false,
): Promise<LocalPricing> {
    const [shop, till] = await Promise.all([offline.shop(), offline.till()]);

    if (shop === null) {
        throw new OfflinePricingError('The offline catalog is not ready yet.');
    }

    const decimals = shop.decimals;
    const saleDiscount = toMinor(state.saleDiscount || '0', decimals);
    const medicinesAmount =
        state.billType === 'opd' && state.medicinesAmount.trim() !== ''
            ? toMinor(state.medicinesAmount, decimals)
            : null;
    const items = await itemsById(state.lines.map((line) => line.variantId));
    const priced = quoteOffline(
        state.lines.map((line) => ({
            variantId: line.variantId,
            unitId: line.unitId,
            quantity: line.quantity,
            lineDiscount: toMinor(line.lineDiscount || '0', decimals),
            priceOverride:
                line.price.trim() === '' ? null : toMinor(line.price, decimals),
        })),
        saleDiscount,
        items,
        shop,
        {
            billType: state.billType,
            medicinesAmount,
            till,
            cashier: offline.state.cashier,
            isQuote,
        },
    );

    // First expiry first out over the cached batches, lines in cart order.
    const day = saleDay(shop.timezone, at);
    const taken = new Map<number, number>();
    const plans = priced.lines.map((line, index): BatchPlan => {
        const item = items.get(line.product_variant_id);
        const cartLine = state.lines[index];

        if (!item?.track_batches || item.batches === undefined) {
            return { batches: [], shortfall: 0 };
        }

        return planLine(
            {
                batches: item.batches,
                baseQuantity: line.base_quantity,
                minDays:
                    item.min_days_to_expiry ?? shop.minDaysToExpiryForSale ?? 0,
                picks:
                    cartLine.batchId === null
                        ? null
                        : [
                              {
                                  batch_id: cartLine.batchId,
                                  quantity: line.base_quantity,
                              },
                          ],
            },
            day,
            taken,
        );
    });

    priced.lines.forEach((line, index) => {
        line.batches = plans[index].batches;
        line.shortfall = plans[index].shortfall;
    });

    const payments = state.payments
        .filter((payment) => payment.amount !== '')
        .map((payment) => ({
            method: payment.method,
            amount: toMinor(payment.amount, decimals),
            reference: payment.reference || null,
        }));
    const referenceRequired = shop.bankTransferReferenceRequired ?? false;
    const { lines, ...totals } = priced;

    return {
        shop,
        quote: {
            totals,
            lines,
            payment:
                payments.length === 0
                    ? null
                    : previewPaymentsOffline(
                          payments,
                          totals.grand_total,
                          referenceRequired,
                      ),
        },
        priced,
        lines,
        plans,
        taxRate: shop.taxEnabled ? shop.taxRate : 0,
        pricesIncludeTax: shop.pricesIncludeTax,
        saleDiscount,
        medicinesAmount,
        opdReceiptMedicines: shop.opdReceiptMedicines ?? 'names',
        payments,
        paymentReferenceRequired: referenceRequired,
        locationId:
            till?.location_id ??
            offline.state.tillLocation?.id ??
            context.locationId,
    };
}

async function applyOfflineQuote(mine: number): Promise<void> {
    try {
        const { quote } = await priceLocally(new Date(), true);

        if (mine === sequence) {
            state.quote = quote;
            state.quoteError = null;
            state.stale = false;
            state.quoteSource = 'offline';
        }
    } catch (error) {
        if (mine === sequence) {
            state.quoteError =
                error instanceof Error
                    ? error.message
                    : 'Could not price the cart.';
            state.stale = true;
        }
    }
}

/**
 * One line as the quote and checkout take it. Keys a retail line never
 * uses (unit, batches, price, prescription) are left out, so a retail
 * request is what it always was. The line's location is the till's: the
 * server defaults it.
 */
function lineBody(line: CartLineState) {
    const prescriber = prescriberFor(line);
    const prescriptionRef = prescriptionRefFor(line);

    return {
        variant_id: line.variantId,
        ...(line.unitId === null ? {} : { unit_id: line.unitId }),
        quantity: line.quantity,
        line_discount: line.lineDiscount || '0',
        ...(line.batchId === null
            ? {}
            : {
                  batches: [
                      { batch_id: line.batchId, quantity: baseQuantity(line) },
                  ],
              }),
        ...(line.price.trim() === '' ? {} : { price: line.price }),
        ...(prescriber === null ? {} : { prescriber }),
        ...(prescriptionRef === null
            ? {}
            : { prescription_ref: prescriptionRef }),
    };
}

function requestBody() {
    const opd = state.billType === 'opd';

    return {
        // The quote knows the till: units sold here, its batches, OPD billing.
        ...(context.deviceUuid === null ? {} : { device: context.deviceUuid }),
        lines: state.lines.map(lineBody),
        sale_discount: state.saleDiscount || '0',
        payments: state.payments
            .filter((payment) => payment.amount !== '')
            .map((payment) => ({
                method: payment.method,
                amount: payment.amount,
                reference: payment.reference || null,
            })),
        ...(opd
            ? {
                  bill_type: 'opd' as const,
                  medicines_amount:
                      state.medicinesAmount.trim() === ''
                          ? null
                          : state.medicinesAmount,
              }
            : {}),
        ...(state.customerName.trim() === ''
            ? {}
            : { customer_name: state.customerName.trim() }),
        ...(state.customerPhone.trim() === ''
            ? {}
            : { customer_phone: state.customerPhone.trim() }),
    };
}

function scheduleQuote(): void {
    state.stale = true;
    clearTimeout(timer);
    timer = setTimeout(() => void refreshQuote(), 200);
}

function startWatching(): void {
    if (watching) {
        return;
    }

    watching = true;
    watch(
        () => JSON.stringify(requestBody()),
        () => scheduleQuote(),
    );
}

/** A line saved by an older till version: the fields it lacks, as they were then. */
function normalizeLine(saved: Partial<CartLineState>): CartLineState {
    const line = {
        unitId: null,
        unit: '',
        factor: 1,
        units: [],
        lineDiscount: '',
        stock: null,
        trackStock: saved.stock !== null && saved.stock !== undefined,
        trackBatches: false,
        wholeUnitsOnly: false,
        allowPriceOverride: false,
        price: '',
        listPrice: null,
        drug: null,
        batches: [],
        minDaysToExpiry: null,
        batchId: null,
        prescriber: null,
        prescriptionRef: null,
        ...saved,
    } as CartLineState;

    line.key = lineKey(line.variantId, line.unitId);

    return line;
}

export function usePosCart() {
    startWatching();

    const itemCount = computed(() => state.lines.length);

    /** OPD bills: the Medicines amount as priced (Σ medicine line totals, from the quote's totals); null otherwise. */
    const medicinesTotal = computed(() =>
        state.billType !== 'opd' || state.quote === null
            ? null
            : (state.quote.totals.medicines_total ?? null),
    );

    /**
     * Why the sale can't be paid yet for its prescriptions, or null: with
     * rx_prescriber 'required', every Rx line needs a prescriber (the server
     * refuses it too). The payment button stays disabled while this is set.
     */
    const prescriptionError = computed(() => {
        if (context.rxPrescriber !== 'required') {
            return null;
        }

        const line = state.lines.find(
            (l) => isRxLine(l) && prescriberFor(l) === null,
        );

        return line
            ? `${line.sku} is prescription only: enter the prescriber.`
            : null;
    });

    /** The sale has a prescription-only or controlled line (show the Prescription fields). */
    const hasRxLines = computed(() => state.lines.some(isRxLine));

    /**
     * Tells the cart what till it is on. Call when the till (or the page's
     * props) change; an empty cart starts again with the till's defaults
     * (OPD bill and its consultation line, the default prescriber). An OPD
     * bill moved to a till without OPD bills (Change till) can't stay one,
     * and there F3 can't switch it back: holding only the consultation the
     * OPD till added, it starts again; else it becomes an itemised bill.
     */
    function configure(options: Partial<CartContext>): void {
        const consultationId = context.opdConsultation?.variant_id ?? null;

        Object.assign(context, options);

        if (state.lines.length === 0) {
            startCart();

            return;
        }

        if (state.billType === 'opd' && !context.opdBilling) {
            if (state.lines.every((l) => l.variantId === consultationId)) {
                newCart();
            } else {
                setBillType('standard');
                state.medicinesAmount = '';
            }
        }
    }

    function startCart(): void {
        state.billType = context.opdBilling ? 'opd' : 'standard';
        state.medicinesAmount = '';
        state.prescription = {
            prescriber: context.defaultPrescriber ?? '',
            ref: '',
        };
        state.customerName = '';
        state.customerPhone = '';

        if (state.billType === 'opd' && context.opdConsultation !== null) {
            addItem(context.opdConsultation);
        }
    }

    /**
     * Adds one of the item, in `unitId` (the unit picker), else the pack its
     * barcode was scanned from, else its default unit. Adding again adds one
     * more to the same item-and-unit line. Returns why it can't be sold here,
     * or null when it was added.
     */
    function addItem(item: PosItem, unitId?: number | null): string | null {
        const unit = defaultUnit(item, unitId);

        if (unit === null) {
            return unitId === undefined || unitId === null
                ? `${item.sku} is not sold at this till.`
                : `${item.sku} is not sold by that unit here.`;
        }

        // Not sellable = nothing sells here or it has no price; a medicine on an OPD bill needs no price.
        if (
            item.sellable === false &&
            !(state.billType === 'opd' && item.track_stock)
        ) {
            return `${item.sku} has no price here and can't be sold.`;
        }

        const id = unit.is_base ? null : unit.id;
        const key = lineKey(item.variant_id, id);
        const existing = state.lines.find((line) => line.key === key);

        if (existing) {
            existing.quantity = roundQuantity(existing.quantity + 1);

            return null;
        }

        state.lines.push({
            key,
            variantId: item.variant_id,
            name: item.name,
            variantName: item.variant_name,
            sku: item.sku,
            unitId: id,
            unit: unit.name,
            factor: unit.factor,
            units: item.units ?? [],
            quantity: 1,
            lineDiscount: '',
            stock: item.track_stock ? item.stock : null,
            trackStock: item.track_stock,
            trackBatches: item.track_batches ?? false,
            wholeUnitsOnly: item.whole_units_only ?? false,
            allowPriceOverride: item.allow_price_override ?? false,
            price: '',
            listPrice: unit.price,
            drug: item.drug ?? null,
            batches: item.batches ?? [],
            minDaysToExpiry: item.min_days_to_expiry ?? null,
            batchId: null,
            prescriber: null,
            prescriptionRef: null,
        });

        return null;
    }

    /**
     * Sells the line in another of its units (the quantity stays as typed).
     * If the cart already has the item in that unit, the two lines merge.
     * Returns why not, or null.
     */
    function setUnit(
        line: CartLineState,
        unitId: number | null,
    ): string | null {
        const units: TillUnit[] =
            line.units.length > 0
                ? line.units
                : [
                      {
                          id: null,
                          name: line.unit,
                          factor: 1,
                          barcode: null,
                          price: line.listPrice,
                          is_base: true,
                          is_default_sale: true,
                          sellable: true,
                      },
                  ];
        const unit =
            unitId === null
                ? units.find((u) => u.is_base)
                : units.find((u) => u.id === unitId);

        if (unit === undefined || !unit.sellable) {
            return `${line.sku} is not sold by that unit here.`;
        }

        const id = unit.is_base ? null : unit.id;
        const key = lineKey(line.variantId, id);

        if (key === line.key) {
            return null;
        }

        const other = state.lines.find((l) => l.key === key);

        if (other) {
            other.quantity = roundQuantity(other.quantity + line.quantity);
            removeLine(line);

            return null;
        }

        line.key = key;
        line.unitId = id;
        line.unit = unit.name;
        line.factor = unit.factor;
        line.listPrice = unit.price;
        // The pick was for the old quantity in base units.
        line.batchId = null;

        return null;
    }

    /** Batch override (cashier.canBatchOverride): the whole line from this batch; null = FEFO. */
    function pickBatch(line: CartLineState, batchId: number | null): void {
        line.batchId = batchId;
    }

    /** A typed unit price on an allow_price_override line ('' = the price list's). */
    function setPrice(line: CartLineState, price: string): void {
        line.price = price;
    }

    /** This line's own prescriber; null = follow the sale's prescription. */
    function setPrescriber(line: CartLineState, value: string | null): void {
        line.prescriber = value;
    }

    function setPrescriptionRef(
        line: CartLineState,
        value: string | null,
    ): void {
        line.prescriptionRef = value;
    }

    /**
     * Switches between an OPD bill and a normal bill for this sale. An OPD
     * bill takes no discounts, so they are cleared; its consultation line is
     * added if the cart has none.
     */
    function setBillType(type: BillType): void {
        if (state.billType === type) {
            return;
        }

        state.billType = type;

        if (type === 'opd') {
            state.saleDiscount = '';
            state.lines.forEach((line) => (line.lineDiscount = ''));

            const consultation = context.opdConsultation;

            if (
                consultation !== null &&
                !state.lines.some(
                    (l) => l.variantId === consultation.variant_id,
                )
            ) {
                addItem(consultation);
            }
        }
    }

    function setQuantity(line: CartLineState, quantity: number): void {
        line.quantity = quantity > 0 ? roundQuantity(quantity) : line.quantity;
    }

    function step(line: CartLineState, delta: number): void {
        const next = roundQuantity(line.quantity + delta);

        if (next <= 0) {
            removeLine(line);

            return;
        }

        line.quantity = next;
    }

    function removeLine(line: CartLineState): void {
        state.lines.splice(state.lines.indexOf(line), 1);
    }

    function addPayment(method: PaymentMethod, amount = ''): void {
        state.payments.push({ key: newUuid(), method, amount, reference: '' });
    }

    function removePayment(row: PaymentRow): void {
        state.payments.splice(state.payments.indexOf(row), 1);
    }

    /** Throw the sale away and start a fresh cart with a new sale id. */
    function newCart(): void {
        state.uuid = newUuid();
        state.lines = [];
        state.saleDiscount = '';
        state.payments = [];
        state.quote = null;
        state.quoteError = null;
        state.stale = false;
        state.quoteSource = 'server';
        sequence++;
        startCart();
    }

    /** The cart as plain JSON, ready to store in IndexedDB. */
    function snapshot(): SavedCart {
        return JSON.parse(
            JSON.stringify({
                uuid: state.uuid,
                lines: state.lines,
                saleDiscount: state.saleDiscount,
                payments: state.payments,
                billType: state.billType,
                medicinesAmount: state.medicinesAmount,
                prescription: state.prescription,
                customerName: state.customerName,
                customerPhone: state.customerPhone,
            }),
        ) as SavedCart;
    }

    function restore(
        saved: Pick<CartState, 'uuid' | 'lines' | 'saleDiscount' | 'payments'> &
            Partial<SavedCart>,
    ): void {
        state.uuid = saved.uuid;
        state.lines = saved.lines.map(normalizeLine);
        state.saleDiscount = saved.saleDiscount;
        state.payments = saved.payments;
        state.billType = saved.billType ?? 'standard';
        state.medicinesAmount = saved.medicinesAmount ?? '';
        state.prescription = saved.prescription ?? {
            prescriber: context.defaultPrescriber ?? '',
            ref: '',
        };
        state.customerName = saved.customerName ?? '';
        state.customerPhone = saved.customerPhone ?? '';
    }

    /** Sale body for POST /pos/checkout: ids, units and quantities, never list prices. */
    function checkoutBody(deviceUuid: string) {
        return { ...requestBody(), device: deviceUuid, uuid: state.uuid };
    }

    /**
     * The sale as the outbox stores it (payload v2): lines with the local
     * prices the customer is charged, the till's location, its FEFO
     * proposal, a till invoice number, and a printable receipt. Refuses (with
     * the same message as checkout) if the payment or a prescription is not right.
     */
    async function offlineSale(): Promise<{
        build: (invoiceNo: string) => {
            payload: SyncSalePayload;
            receipt: OfflineReceipt;
        };
        changeDue: number;
        grandTotal: number;
    }> {
        const soldAt = new Date();
        const priced = await priceLocally(soldAt);
        const totals = priced.quote.totals;
        const preview = previewPaymentsOffline(
            priced.payments,
            totals.grand_total,
            priced.paymentReferenceRequired,
        );

        if (priced.payments.length === 0) {
            throw new OfflinePricingError('Add at least one payment.');
        }

        if (preview.error !== null) {
            throw new OfflinePricingError(preview.error);
        }

        const cartLines = [...state.lines];
        const prescribers = cartLines.map(prescriberFor);
        const prescriptionRefs = cartLines.map(prescriptionRefFor);

        checkPrescribers(priced.lines, prescribers, priced.shop);

        const uuid = state.uuid;
        const opd = state.billType === 'opd';
        const customerName = state.customerName.trim() || null;
        const customerPhone = state.customerPhone.trim() || null;
        const cashier = offline.state.cashier;
        const methodLabel = (method: string) =>
            method.replace('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase());

        const build = (invoiceNo: string) => ({
            payload: {
                uuid,
                invoice_no: invoiceNo,
                sold_at: soldAt.toISOString(),
                cashier_id: cashier.id,
                lines: priced.lines.map((line, index) => ({
                    variant_id: line.product_variant_id,
                    quantity: line.quantity,
                    unit_price: line.unit_price,
                    line_discount: line.line_discount,
                    ...(cartLines[index].unitId === null
                        ? {}
                        : { unit_id: cartLines[index].unitId }),
                    ...(priced.locationId === null
                        ? {}
                        : { location_id: priced.locationId }),
                    ...(priced.plans[index].batches.length === 0
                        ? {}
                        : { batches: toProposal(priced.plans[index]) }),
                    ...(prescribers[index] === null
                        ? {}
                        : { prescriber: prescribers[index] }),
                    ...(prescriptionRefs[index] === null
                        ? {}
                        : { prescription_ref: prescriptionRefs[index] }),
                    ...(line.price_override ? { price_override: true } : {}),
                })),
                sale_discount: priced.saleDiscount,
                payments: priced.payments,
                grand_total: totals.grand_total,
                tax_rate: priced.taxRate,
                prices_include_tax: priced.pricesIncludeTax,
                ...(opd
                    ? {
                          bill_type: 'opd' as const,
                          medicines_amount: priced.medicinesAmount,
                      }
                    : {}),
                ...(customerName === null
                    ? {}
                    : { customer_name: customerName }),
                ...(customerPhone === null
                    ? {}
                    : { customer_phone: customerPhone }),
            },
            receipt: {
                invoiceNo,
                soldAt: soldAt.toLocaleString('sv-SE', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit',
                }),
                cashier: cashier.name,
                subtotal: totals.subtotal,
                discountTotal: totals.discount_total,
                taxTotal: totals.tax_total,
                taxRate: priced.taxRate,
                pricesIncludeTax: priced.pricesIncludeTax,
                grandTotal: totals.grand_total,
                paidTotal: preview.paid_total,
                changeDue: preview.change_due,
                items: priced.lines.map((line, index) => ({
                    description: line.description,
                    sku: line.sku,
                    quantity: formatQuantity(line.quantity),
                    // An OPD medicine line's share is not printed (nor known: null), as on the server's receipt.
                    unitPrice: line.is_opd_medicine ? null : line.unit_price,
                    discount:
                        line.sale_discount_share === null
                            ? null
                            : line.line_discount + line.sale_discount_share,
                    lineTotal: line.line_total,
                    taxExempt: line.tax_category === 'exempt',
                    unitName: line.unit_name,
                    strength: cartLines[index].drug?.strength ?? null,
                    baseUnitName:
                        cartLines[index].units.find((unit) => unit.is_base)
                            ?.name ??
                        (cartLines[index].factor === 1
                            ? cartLines[index].unit
                            : null),
                    isOpdMedicine: line.is_opd_medicine,
                    prescriber: prescribers[index],
                    prescriptionRef: prescriptionRefs[index],
                    ...(priced.plans[index].batches.length === 0
                        ? {}
                        : {
                              batches: priced.plans[index].batches.map(
                                  (piece) => ({
                                      batchNo: piece.batch_no,
                                      // As the server's receipt prints it (ReceiptService): MM/YYYY.
                                      expiry:
                                          piece.expiry === null
                                              ? null
                                              : `${piece.expiry.slice(5, 7)}/${piece.expiry.slice(0, 4)}`,
                                      quantity: formatQuantity(piece.quantity),
                                  }),
                              ),
                          }),
                })),
                payments: priced.payments.map((payment) => ({
                    method: methodLabel(payment.method),
                    amount: payment.amount,
                    reference: payment.reference,
                })),
                billType: state.billType,
                opdReceiptMedicines: priced.opdReceiptMedicines,
                medicinesTotal: totals.medicines_total,
            },
        });

        return {
            build,
            changeDue: preview.change_due,
            grandTotal: totals.grand_total,
        };
    }

    return {
        state,
        context,
        itemCount,
        medicinesTotal,
        prescriptionError,
        hasRxLines,
        configure,
        offlineSale,
        addItem,
        setUnit,
        pickBatch,
        setPrice,
        setPrescriber,
        setPrescriptionRef,
        setBillType,
        setQuantity,
        step,
        removeLine,
        addPayment,
        removePayment,
        newCart,
        snapshot,
        restore,
        checkoutBody,
        refreshQuote,
        baseQuantity,
        isRxLine,
        isOpdMedicineLine,
        prescriberFor,
        prescriptionRefFor,
        defaultUnit,
    };
}
