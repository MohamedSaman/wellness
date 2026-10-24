import type {
    BillType,
    DrugSchedule,
    PaymentMethod,
    PaymentPreview,
    PosItem,
    PosUnit,
    Quote,
    QuoteLine,
} from '@/types/pos';
import type { CatalogItem, OfflineShop, OfflineTill } from './db';

/**
 * Offline pricing. A line-for-line copy of CheckoutService::calculate(),
 * unitFor(), checkDiscountLimit() and checkPayments() (and App\Support\Money),
 * so a sale priced here comes out exactly as the server would price it with
 * the same prices. Only used while the server cannot be reached; online, the
 * server's quote is always the one shown. Keep the two in step.
 *
 * Offline the till plays the online server's part: it refuses what an online
 * checkout would refuse (a unit not sold here, part units, a price override
 * the cashier may not make, an OPD bill without its Medicines amount...).
 * The server's replay of the sale only flags those, since the sale happened.
 *
 * One intended difference (docs/PHARMACY.md §9 branch 8): an OPD bill's
 * Medicines amount is split over its medicine lines by list price, but
 * online the server weighs an unpriced line by its cost, which the till
 * never has; here it weighs 0 (all 0: 1 each). So the per-line splits can
 * differ; the totals (the consultation plus the amount typed) are the same.
 * Each medicine line is sent with the price it was weighed by
 * (`unit_price`), and the server's replay weighs by those, so the shares,
 * the tax and the total it records are the ones charged here, even after a
 * price change the till had not synced yet.
 *
 * As the server's quote does, the result never shows a medicine line's
 * share: on an OPD bill's medicine lines line_total, tax_amount and
 * sale_discount_share are null, and the totals carry medicines_total and
 * medicines_tax (null on a standard bill), so the screen is the same online
 * and offline. They also carry medicines_list_value and medicines_below_list
 * (CheckoutService::medicinesListValue() / belowList()): the priced
 * medicines at list price and whether the typed amount is below the shop's
 * warning share of it.
 */
export class OfflinePricingError extends Error {}

export type OfflineCartLine = {
    variantId: number;
    /** Null = the base unit (always for an item cached without units). */
    unitId: number | null;
    /** In the unit sold. */
    quantity: number;
    /** Minor units. */
    lineDiscount: number;
    /** A typed unit price (minor units) on a price-override item; null = the price list's. */
    priceOverride?: number | null;
};

export type OfflinePayment = {
    method: PaymentMethod;
    amount: number;
    reference?: string | null;
};

/** What the sale is, beyond its lines: the bill type and who rings it up where. */
export type OfflineSaleContext = {
    billType: BillType;
    /** OPD bills: the typed Medicines amount, minor units; null when not typed. */
    medicinesAmount: number | null;
    /** The till as the catalog last described it (OPD billing, location). */
    till: OfflineTill | null;
    /**
     * Pricing for the screen, not the sale: like the server's quote, an OPD
     * bill without its Medicines amount yet prices the medicines at nothing
     * instead of refusing. The sale itself (false, the default) refuses it.
     */
    isQuote?: boolean;
    cashier: {
        unlimitedDiscount: boolean;
        canOverridePrice?: boolean;
        canOpdBill?: boolean;
    };
};

/** Quantity::normalize(): 3 decimal places. */
export function normalizeQuantity(value: number): number {
    return roundHalfAwayFromZero(value, 3);
}

/** Quantity::isWhole(). */
export function isWholeQuantity(value: number): boolean {
    return Math.round(normalizeQuantity(value) * 1000) % 1000 === 0;
}

/** Quantity::format(): "30", "1.5", "0.333". */
function formatQuantity(value: number): string {
    return String(normalizeQuantity(value));
}

/** PHP round(): halves go away from zero (Math.round sends -2.5 to -2). */
function roundHalfAwayFromZero(value: number, places = 0): number {
    const factor = 10 ** places;
    const result = Math.round(Math.abs(value) * factor) / factor;

    return value < 0 ? -result : result;
}

/** Money::toMinor(): "1,250.50" -> 125050. */
export function toMinor(amount: string | number, decimals: number): number {
    const clean =
        typeof amount === 'string' ? amount.replace(/[,\s]/g, '') : amount;
    const value = Number(clean);

    return Number.isFinite(value)
        ? roundHalfAwayFromZero(value * 10 ** decimals)
        : 0;
}

/** Money::multiply(). */
function multiply(unitMinor: number, quantity: number): number {
    return roundHalfAwayFromZero(unitMinor * normalizeQuantity(quantity));
}

/** Money::allocate(): proportional split, remainder to the largest weight. */
export function allocate(amount: number, weights: number[]): number[] {
    const total = weights.reduce((sum, weight) => sum + weight, 0);
    const parts = weights.map(() => 0);

    if (amount === 0 || total <= 0 || weights.length === 0) {
        return parts;
    }

    weights.forEach((weight, index) => {
        parts[index] = Number(
            (BigInt(amount) * BigInt(weight)) / BigInt(total),
        );
    });

    const remainder = amount - parts.reduce((sum, part) => sum + part, 0);
    parts[weights.indexOf(Math.max(...weights))] += remainder;

    return parts;
}

/** Money::format() without a currency, as used in server messages. */
function formatPlain(minor: number): string {
    return (minor / 100).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

/** A prescription-only or controlled medicine: the line takes a prescriber. */
export function isRxSchedule(
    schedule: DrugSchedule | null | undefined,
): boolean {
    return schedule === 'prescription_only' || schedule === 'controlled';
}

/** An item's units; an item cached before units is its base unit alone (id null). */
export type TillUnit = Omit<PosUnit, 'id'> & { id: number | null };

export function unitsOf(item: PosItem): TillUnit[] {
    if (item.units && item.units.length > 0) {
        return item.units;
    }

    return [
        {
            id: null,
            name: item.unit,
            factor: 1,
            barcode: item.barcode,
            price: item.price,
            is_base: true,
            is_default_sale: true,
            sellable: true,
        },
    ];
}

/**
 * CheckoutService::unitFor() at the till: the unit asked for, or the base
 * unit; it must sell at this till's location (ProductVariant::canSellUnitAt(),
 * cached as `sellable`). `locationName` names the till's location in the
 * refusal, as the server's quote with a till does.
 */
export function unitFor(
    item: PosItem,
    unitId: number | null,
    locationName: string | null = null,
): TillUnit {
    const units = unitsOf(item);
    const unit =
        unitId === null
            ? units.find((u) => u.is_base)
            : units.find((u) => u.id === unitId);

    if (unit === undefined) {
        throw new OfflinePricingError(
            unitId === null
                ? `${item.sku} has no base unit.`
                : `That unit is not one of ${item.sku}'s units.`,
        );
    }

    if (!unit.sellable) {
        throw new OfflinePricingError(
            locationName === null
                ? `${item.sku} is not sold by the ${unit.name}.`
                : `${item.sku} is not sold by the ${unit.name} at ${locationName}.`,
        );
    }

    return unit;
}

export type OfflineQuoteLine = QuoteLine & {
    name: string;
    variant_name: string | null;
    variant_unit_id: number | null;
    unit_name: string;
    unit_factor: number;
    base_quantity: number;
    list_price: number | null;
    is_opd_medicine: boolean;
    drug_schedule: DrugSchedule | null;
    /** The line's unit price was typed (price override). */
    price_override: boolean;
};

/** The totals (with medicines_total / medicines_tax on an OPD bill) and the lines. */
export type OfflineQuote = Quote['totals'] & {
    lines: OfflineQuoteLine[];
};

/**
 * Prices a cart from the local catalog. Throws OfflinePricingError with the
 * same message the server would give.
 */
export function quoteOffline(
    lines: OfflineCartLine[],
    saleDiscount: number,
    items: Map<number, CatalogItem>,
    shop: OfflineShop,
    context: OfflineSaleContext,
): OfflineQuote {
    if (lines.length === 0) {
        throw new OfflinePricingError('The cart is empty.');
    }

    if (saleDiscount < 0) {
        throw new OfflinePricingError('Discount cannot be negative.');
    }

    // CheckoutService::loadVariants(): every item first, in cart order.
    for (const cartLine of lines) {
        if (!items.has(cartLine.variantId)) {
            throw new OfflinePricingError(
                `Item #${cartLine.variantId} was not found.`,
            );
        }
    }

    // Then, as CheckoutService::price() does, the typed prices and the OPD rules.
    for (const cartLine of lines) {
        const override = cartLine.priceOverride ?? null;

        if (override !== null) {
            checkPriceOverride(
                items.get(cartLine.variantId) as CatalogItem,
                override,
                context,
            );
        }
    }

    const opd = context.billType === 'opd';

    if (opd) {
        checkOpdBill(context, saleDiscount, lines, items);
    }

    const locationName = context.till?.location_name ?? null;
    const priced: (OfflineQuoteLine & { gross: number })[] = [];
    const nets: number[] = [];

    for (const cartLine of lines) {
        const quantity = normalizeQuantity(cartLine.quantity);

        if (quantity <= 0) {
            throw new OfflinePricingError(
                'Quantities must be greater than zero.',
            );
        }

        const item = items.get(cartLine.variantId) as CatalogItem;
        const unit = unitFor(item, cartLine.unitId, locationName);
        const baseQuantity = normalizeQuantity(quantity * unit.factor);

        // StockService::assertWholeUnits(): whole units of the unit sold.
        if (item.whole_units_only && !isWholeQuantity(quantity)) {
            throw new OfflinePricingError(
                `${item.sku} is sold, received and counted in whole units only: ${formatQuantity(quantity)} ${unit.name} is not allowed.`,
            );
        }

        // A register of controlled drugs needs the server (docs/PHARMACY.md §7.4).
        if (shop.controlledRegister && item.drug?.schedule === 'controlled') {
            throw new OfflinePricingError(
                `${item.sku} is a controlled drug: it can't be sold while the till is offline.`,
            );
        }

        // OPD bill: a stock item's total is its share of the Medicines amount, priced or not.
        const isOpdMedicine = opd && item.track_stock;
        const override = cartLine.priceOverride ?? null;
        let unitPrice: number;

        if (isOpdMedicine) {
            unitPrice = unit.price ?? 0;
        } else if (override !== null) {
            unitPrice = override;
        } else if (unit.price !== null) {
            unitPrice = unit.price;
        } else {
            throw new OfflinePricingError(
                unit.is_base
                    ? `${item.sku} has no price on the price list.`
                    : `${item.sku} has no price for a ${unit.name} on the price list.`,
            );
        }

        // Weighed by list gross on an OPD bill; replaced by the share below.
        const gross = multiply(unitPrice, quantity);

        if (
            cartLine.lineDiscount < 0 ||
            cartLine.lineDiscount > (isOpdMedicine ? 0 : gross)
        ) {
            throw new OfflinePricingError(`Invalid discount on ${item.sku}.`);
        }

        nets.push(gross - cartLine.lineDiscount);
        priced.push({
            product_variant_id: item.variant_id,
            description:
                [item.name, item.variant_name ?? ''].join(' ').trim() +
                (unit.is_base ? '' : ` (${unit.name})`),
            name: item.name,
            variant_name: item.variant_name,
            sku: item.sku,
            variant_unit_id: unit.id,
            unit_name: unit.name,
            unit_factor: unit.factor,
            quantity,
            base_quantity: baseQuantity,
            unit_price: unitPrice,
            list_price: isOpdMedicine || override !== null ? unit.price : null,
            is_opd_medicine: isOpdMedicine,
            drug_schedule: item.drug?.schedule ?? null,
            price_override: override !== null && !isOpdMedicine,
            line_discount: cartLine.lineDiscount,
            sale_discount_share: 0,
            tax_amount: 0,
            // Items cached before tax categories existed were all standard.
            tax_category: item.tax_category ?? 'standard',
            line_total: 0,
            gross,
        });
    }

    if (opd) {
        allocateMedicines(priced, nets, context);
    }

    if (saleDiscount > nets.reduce((sum, net) => sum + net, 0)) {
        throw new OfflinePricingError(
            'The discount is larger than the sale total.',
        );
    }

    const shares = allocate(saleDiscount, nets);
    const shopRate = shop.taxEnabled ? shop.taxRate : 0;

    let subtotal = 0;
    let taxTotal = 0;
    let grandTotal = 0;
    let lineDiscounts = 0;
    let medicinesTotal = 0;
    let medicinesTax = 0;

    priced.forEach((line, index) => {
        const afterDiscount = nets[index] - shares[index];
        // The shop's rate applies to standard items only; exempt lines carry no tax.
        const rate = line.tax_category === 'exempt' ? 0 : shopRate;
        let tax: number;
        let lineTotal: number;

        if (rate > 0 && shop.pricesIncludeTax) {
            tax = roundHalfAwayFromZero((afterDiscount * rate) / (100 + rate));
            lineTotal = afterDiscount;
        } else {
            tax = roundHalfAwayFromZero((afterDiscount * rate) / 100);
            lineTotal = afterDiscount + tax;
        }

        subtotal += line.gross;
        lineDiscounts += line.line_discount;
        taxTotal += tax;
        grandTotal += lineTotal;

        if (line.is_opd_medicine) {
            // Only the bill's Medicines figures are shown, as the server's quote.
            medicinesTotal += lineTotal;
            medicinesTax += tax;
            line.sale_discount_share = null;
            line.tax_amount = null;
            line.line_total = null;
        } else {
            line.sale_discount_share = shares[index];
            line.tax_amount = tax;
            line.line_total = lineTotal;
        }
    });

    const discountTotal = lineDiscounts + saleDiscount;

    checkDiscountLimit(
        shop,
        context.cashier.unlimitedDiscount,
        subtotal,
        discountTotal,
    );

    return {
        subtotal,
        discount_total: discountTotal,
        tax_total: taxTotal,
        grand_total: grandTotal,
        medicines_total: opd ? medicinesTotal : null,
        medicines_tax: opd ? medicinesTax : null,
        medicines_list_value: opd ? medicinesListValue(priced) : null,
        medicines_below_list: opd
            ? belowList(
                  shop,
                  context.medicinesAmount,
                  medicinesListValue(priced),
              )
            : null,
        lines: priced.map(({ gross: _gross, ...line }) => line),
    };
}

/**
 * CheckoutService::checkOpdBill() (§5.6), in its order: the till, the
 * permission, no discounts, the Medicines amount (needed when medicines
 * were dispensed, except on a quote) and medicines when there is one.
 */
function checkOpdBill(
    context: OfflineSaleContext,
    saleDiscount: number,
    lines: OfflineCartLine[],
    items: Map<number, CatalogItem>,
): void {
    if (!context.till?.opd_billing) {
        throw new OfflinePricingError('This till does not take OPD bills.');
    }

    if (!context.cashier.canOpdBill) {
        throw new OfflinePricingError(
            'You are not allowed to ring up OPD bills.',
        );
    }

    if (saleDiscount > 0 || lines.some((line) => line.lineDiscount > 0)) {
        throw new OfflinePricingError('OPD bills take no discounts.');
    }

    const amount = context.medicinesAmount;

    if (amount !== null && amount < 0) {
        throw new OfflinePricingError(
            'The Medicines amount cannot be negative.',
        );
    }

    const hasMedicines = lines.some(
        (line) => items.get(line.variantId)?.track_stock ?? false,
    );

    if (hasMedicines && amount === null && !context.isQuote) {
        throw new OfflinePricingError('Enter the Medicines amount.');
    }

    if (!hasMedicines && (amount ?? 0) > 0) {
        throw new OfflinePricingError('Add the medicines dispensed.');
    }
}

/**
 * The price override rule (§5.4): a non-stock product that allows it, a
 * cashier with pos.price_override, a price above zero.
 */
function checkPriceOverride(
    item: CatalogItem,
    price: number,
    context: OfflineSaleContext,
): void {
    if (!item.allow_price_override) {
        throw new OfflinePricingError(
            `${item.sku}'s price can't be changed at the till.`,
        );
    }

    if (!context.cashier.canOverridePrice) {
        throw new OfflinePricingError('You are not allowed to change prices.');
    }

    if (price <= 0) {
        throw new OfflinePricingError(
            `The price of ${item.sku} must be more than zero.`,
        );
    }
}

/**
 * Spreads the Medicines amount over the medicine lines (§5.6), in place:
 * each medicine line's gross and net become its share. Weight = list gross
 * (unit price x quantity); unpriced lines weigh 0 (the server weighs them by
 * cost online, see the top of this file; on replay it weighs by the prices
 * sent from here); all 0 = 1 each.
 */
function allocateMedicines(
    priced: (OfflineQuoteLine & { gross: number })[],
    nets: number[],
    context: OfflineSaleContext,
): void {
    const medicines = priced
        .map((line, index) => ({ line, index }))
        .filter(({ line }) => line.is_opd_medicine);
    // checkOpdBill() refused a missing amount, except on a quote: nothing typed yet is nothing.
    const amount = Math.max(0, context.medicinesAmount ?? 0);

    if (medicines.length === 0) {
        return;
    }

    let weights = medicines.map(({ line }) =>
        line.list_price === null ? 0 : multiply(line.list_price, line.quantity),
    );

    if (weights.every((weight) => weight === 0)) {
        weights = weights.map(() => 1);
    }

    const shares = allocate(amount, weights);

    medicines.forEach(({ line, index }, i) => {
        line.gross = shares[i];
        nets[index] = shares[i];
    });
}

/**
 * CheckoutService::medicinesListValue(): Σ list price × quantity of an OPD
 * bill's medicine lines that have a price (an unpriced one adds nothing).
 */
export function medicinesListValue(lines: OfflineQuoteLine[]): number {
    return lines.reduce(
        (sum, line) =>
            line.is_opd_medicine && line.list_price !== null
                ? sum + multiply(line.list_price, line.quantity)
                : sum,
        0,
    );
}

/**
 * CheckoutService::belowList(): the typed Medicines amount is below the
 * shop's warning share of the list value (0% = never; nothing typed or
 * nothing priced = no warning). Whole numbers, as on the server.
 */
export function belowList(
    shop: OfflineShop,
    medicinesAmount: number | null,
    listValue: number,
): boolean {
    const percent = Math.max(
        0,
        Math.min(100, Math.trunc(shop.opdBelowListWarningPercent ?? 100)),
    );

    return (
        percent > 0 &&
        medicinesAmount !== null &&
        listValue > 0 &&
        medicinesAmount * 100 < percent * listValue
    );
}

function checkDiscountLimit(
    shop: OfflineShop,
    unlimited: boolean,
    subtotal: number,
    discount: number,
): void {
    if (discount === 0 || subtotal === 0 || unlimited) {
        return;
    }

    const percent = (discount / subtotal) * 100;
    const limit = shop.maxCashierDiscountPercent;

    if (percent > limit + 0.0001) {
        throw new OfflinePricingError(
            `Discount of ${percent.toFixed(1)}% is above your limit of ${limit.toFixed(1)}%. Ask the owner to approve it.`,
        );
    }
}

/**
 * CheckoutService::previewPayments() / checkPayments(), for the payment
 * screen. With `referenceRequired` (shop setting
 * bank_transfer_reference_required) a bank transfer needs its reference.
 */
export function previewPaymentsOffline(
    payments: OfflinePayment[],
    grandTotal: number,
    referenceRequired = false,
): PaymentPreview {
    const paid = payments.reduce((sum, payment) => sum + payment.amount, 0);
    let error: string | null = null;
    let cash = 0;

    for (const payment of payments) {
        if (payment.amount <= 0) {
            error ??= 'Payment amounts must be greater than zero.';
        }

        cash += payment.method === 'cash' ? payment.amount : 0;
    }

    if (error === null && paid < grandTotal) {
        error = `Payment is short by ${formatPlain(grandTotal - paid)}.`;
    }

    if (error === null && paid - grandTotal > cash) {
        error =
            'Card and transfer payments cannot be more than the amount due.';
    }

    if (
        error === null &&
        referenceRequired &&
        payments.some(
            (payment) =>
                payment.method === 'bank_transfer' &&
                (payment.reference ?? '').trim() === '',
        )
    ) {
        error = 'Enter the bank transfer reference.';
    }

    return {
        paid_total: paid,
        change_due: Math.max(0, paid - grandTotal),
        short_by: Math.max(0, grandTotal - paid),
        error,
    };
}

/**
 * The prescription rule (§5.5) before payment: with the shop setting
 * rx_prescriber = 'required', every prescription-only or controlled line
 * needs a prescriber. `prescribers` is keyed like `lines`.
 */
export function checkPrescribers(
    lines: Pick<OfflineQuoteLine, 'sku' | 'drug_schedule'>[],
    prescribers: (string | null)[],
    shop: OfflineShop,
): void {
    if ((shop.rxPrescriber ?? 'prompt') !== 'required') {
        return;
    }

    lines.forEach((line, index) => {
        if (
            isRxSchedule(line.drug_schedule) &&
            (prescribers[index] ?? '').trim() === ''
        ) {
            throw new OfflinePricingError(
                `${line.sku} is prescription only: enter the prescriber.`,
            );
        }
    });
}
