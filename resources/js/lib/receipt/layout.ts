/**
 * The thermal receipt as lines of fixed-width text: 48 characters on 80 mm
 * paper, 32 on 58 mm (docs/PHARMACY.md §9, branch 9).
 *
 * A mirror of saleLayout() in app/Services/Receipts/ReceiptLayout.php
 * (ReceiptLayout::sale()), for the receipts the till prints itself (sales
 * made offline); everything the server prints is laid out by the server.
 * Change both together: ReceiptLayoutParityTest runs this file under node
 * and compares its lines with the server's.
 *
 * Self-contained on purpose (no imports), so node can run it as it is.
 */

export type ThermalLine = { text: string; bold: boolean; tall: boolean };

export type PrintOptions = {
    paperWidth: number;
    showBatch: boolean;
    showExpiry: boolean;
    showPrescriber: boolean;
};

export type LayoutShop = {
    name: string;
    address: string | null;
    phone: string | null;
    taxNumber: string | null;
    header: string | null;
    footer: string | null;
    currency: string;
    decimals: number;
    receipt?: PrintOptions;
};

export type LayoutItem = {
    description: string;
    strength?: string | null;
    unitName?: string | null;
    packName?: string | null;
    baseUnitName?: string | null;
    location?: string | null;
    quantity: string;
    returnedQuantity?: string | null;
    returnedBaseQuantity?: string | null;
    unitPrice?: number | null;
    discount?: number | null;
    lineTotal?: number | null;
    taxExempt?: boolean;
    isOpdMedicine?: boolean;
    prescriber?: string | null;
    prescriptionRef?: string | null;
    batches?: {
        batchNo: string | null;
        expiry: string | null;
        quantity: string;
    }[];
};

export type LayoutSale = {
    invoiceNo: string;
    status?: string;
    billType?: string;
    opdReceiptMedicines?: string;
    medicinesTotal?: number | null;
    soldAt: string;
    cashier: string;
    till?: {
        code: string;
        name?: string | null;
        location?: string | null;
    } | null;
    subtotal: number;
    discountTotal: number;
    taxTotal: number;
    taxRate: number;
    pricesIncludeTax: boolean;
    grandTotal: number;
    changeDue: number;
    refundedTotal?: number;
    items: LayoutItem[];
    payments: { method: string; amount: number; reference?: string | null }[];
    reprint?: { printedAt: string | null; printedBy: string } | null;
};

export const COLUMNS: Record<number, number> = { 80: 48, 58: 32 };

export function columns(paperWidth: number): number {
    return COLUMNS[paperWidth] ?? COLUMNS[80];
}

/** "2026-10-21 14:05" as printed: "21/10/2026 14:05". */
export function receiptDate(value: string): string {
    return value.replace(/^(\d{4})-(\d{2})-(\d{2})/, '$3/$2/$1');
}

/** Printable ASCII only: thermal printers' code pages have no curly quotes or accents. */
export function ascii(text: string): string {
    return text
        .replace(/[‘’]/g, "'")
        .replace(/[“”]/g, '"')
        .replace(/[–—]/g, '-')
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[\t\r\n]/g, ' ')
        .replace(/[^\x20-\x7E]/g, '?');
}

const filled = (value: string | number | null | undefined): boolean =>
    value !== null && value !== undefined && String(value).trim() !== '';

/** 125050 with 2 decimals -> "1,250.50" (PHP's number_format). */
function amount(minor: number, decimals: number): string {
    const digits = String(Math.abs(Math.trunc(minor))).padStart(
        decimals + 1,
        '0',
    );
    const whole = digits.slice(0, digits.length - decimals);
    const fraction = decimals > 0 ? `.${digits.slice(-decimals)}` : '';

    return `${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}${fraction}`;
}

/**
 * "Panadol 500 mg Tablets": the line's description without the pack it was
 * sold in (that is printed with the quantity), and its strength when the
 * name does not already say it.
 */
export function itemName(item: LayoutItem): string {
    let name = item.description;
    const unit = item.packName ?? item.unitName ?? '';
    const suffix = ` (${unit})`;

    if (unit !== '' && name.endsWith(suffix)) {
        name = name.slice(0, -suffix.length);
    }

    const strength = (item.strength ?? '').trim();
    const squash = (text: string) => text.replace(/\s+/g, '').toLowerCase();

    if (strength !== '' && !squash(name).includes(squash(strength))) {
        name += ` ${strength}`;
    }

    return name;
}

/** A sale receipt: ReceiptLayout::sale(). */
export function saleLayout(shop: LayoutShop, sale: LayoutSale): ThermalLine[] {
    const options = shop.receipt;
    const width = columns(options?.paperWidth ?? 80);
    const showBatch = options?.showBatch ?? true;
    const showExpiry = options?.showExpiry ?? true;
    const showPrescriber = options?.showPrescriber ?? false;
    const decimals = shop.decimals ?? 2;
    const taxOn = (sale.taxRate ?? 0) > 0;
    const lines: ThermalLine[] = [];

    const push = (text: string, bold = false, tall = false) =>
        lines.push({ text: text.replace(/\s+$/, ''), bold, tall });

    const money = (minor: number, exempt = false): string => {
        const text = (minor < 0 ? '-' : '') + amount(minor, decimals);

        return taxOn ? text + (exempt ? ' E' : '  ') : text;
    };

    const wrap = (
        text: string,
        lineWidth: number,
        indent = 0,
        hang = 0,
    ): string[] => {
        const words = text
            .trim()
            .split(/\s+/)
            .filter((word) => word !== '');
        const result: string[] = [];
        let line = '';
        let pad = ' '.repeat(indent);

        for (let word of words) {
            let room = lineWidth - pad.length;

            if (line !== '' && line.length + 1 + word.length <= room) {
                line += ` ${word}`;
                continue;
            }

            if (line !== '') {
                result.push(pad + line);
                pad = ' '.repeat(hang);
                room = lineWidth - pad.length;
            }

            while (word.length > Math.max(1, room)) {
                result.push(pad + word.slice(0, Math.max(1, room)));
                word = word.slice(Math.max(1, room));
                pad = ' '.repeat(hang);
                room = lineWidth - pad.length;
            }

            line = word;
        }

        if (line !== '') {
            result.push(pad + line);
        }

        return result;
    };

    const row = (
        leftText: string,
        rightText = '',
        indent = 0,
        hang = 0,
        bold = false,
        isAmount = true,
    ): void => {
        const left = ascii(leftText);
        const right = ascii(rightText);

        // A value (not an amount) that is too long for one line goes under its label.
        if (
            !isAmount &&
            right !== '' &&
            left.length + 1 + right.length > width
        ) {
            row(left, '', indent, hang, bold, false);
            row('', right, indent, hang, bold, false);

            return;
        }

        const wrapped = wrap(left, width, indent, indent + hang);

        if (right === '') {
            wrapped.forEach((line) => push(line, bold));

            return;
        }

        let last = wrapped.pop() ?? ' '.repeat(indent);

        if (last.length + 1 + right.length > width) {
            const lastIndent = wrapped.length === 0 ? indent : indent + hang;
            const more = wrap(
                last.replace(/^\s+/, ''),
                Math.max(1, width - right.length - 1),
                lastIndent,
                indent + hang,
            );
            last = more.pop() ?? '';
            wrapped.push(...more);

            if (last.length + 1 + right.length > width) {
                wrapped.push(last);
                last = '';
            }
        }

        wrapped.forEach((line) => push(line, bold));
        push(last.padEnd(width - right.length) + right, bold);
    };

    const text = (value: string, indent = 0, hang = 0, bold = false) =>
        row(value, '', indent, hang, bold, false);

    const center = (value: string, bold = false, tall = false) => {
        for (const wrapped of wrap(ascii(value), width)) {
            const line = wrapped.trim();
            push(
                ' '.repeat(Math.floor((width - line.length) / 2)) + line,
                bold,
                tall,
            );
        }
    };

    const rule = (character = '-') => push(character.repeat(width));

    const quantity = (value: string, unit?: string | null) =>
        filled(unit) ? `${value} ${unit}` : value;

    const fullyReturned = (item: LayoutItem) =>
        filled(item.returnedQuantity) &&
        Number(item.returnedQuantity) >= Number(item.quantity);

    const returned = (item: LayoutItem): string => {
        const value = String(item.returnedQuantity);

        if (filled(item.returnedBaseQuantity) && !/^\d+$/.test(value)) {
            return quantity(
                String(item.returnedBaseQuantity),
                item.baseUnitName,
            );
        }

        return quantity(value, item.unitName);
    };

    const details = (item: LayoutItem, indent: number) => {
        const batches = item.batches ?? [];

        for (const batch of batches) {
            const parts: string[] = [];

            if (showBatch) {
                parts.push(
                    batch.batchNo === null
                        ? 'Batch to be confirmed'
                        : `Batch ${batch.batchNo}`,
                );
            }

            if (showExpiry && batch.expiry !== null) {
                parts.push(`Exp ${batch.expiry}`);
            }

            if (parts.length > 0 && batches.length > 1) {
                parts.push(`x ${quantity(batch.quantity, item.baseUnitName)}`);
            }

            if (parts.length > 0) {
                text(parts.join(' '), indent, 2);
            }
        }

        if (showPrescriber && filled(item.prescriber)) {
            text(`Prescriber: ${item.prescriber}`, indent, 2);
        }

        if (showPrescriber && filled(item.prescriptionRef)) {
            text(`Rx ref: ${item.prescriptionRef}`, indent, 2);
        }

        if (filled(item.returnedQuantity) && !fullyReturned(item)) {
            text(`Returned ${returned(item)}`, indent, 2);
        }
    };

    const itemised = (item: LayoutItem) => {
        text(itemName(item) + (fullyReturned(item) ? ' (RETURNED)' : ''), 0, 2);

        const sold = quantity(item.quantity, item.unitName);
        const left =
            item.unitPrice !== undefined && item.unitPrice !== null
                ? `${sold} x ${amount(item.unitPrice, decimals)}`
                : sold;

        row(
            left,
            item.lineTotal === null || item.lineTotal === undefined
                ? ''
                : money(item.lineTotal, item.taxExempt ?? false),
            2,
            2,
        );

        if ((item.discount ?? 0) > 0) {
            row('Discount', money(-(item.discount ?? 0)), 2);
        }

        details(item, 2);
    };

    // REPRINT, the header, VOIDED
    if (sale.reprint) {
        center('*** REPRINT ***', true, true);
    }

    center(shop.name.toUpperCase(), true, true);

    for (const value of [
        shop.address,
        filled(shop.phone) ? `Tel ${shop.phone}` : null,
        filled(shop.taxNumber) ? `Tax no. ${shop.taxNumber}` : null,
        ...(shop.header ?? '').split(/\r\n|\r|\n/),
    ]) {
        if (filled(value)) {
            center(String(value));
        }
    }

    rule('=');

    if (sale.status === 'voided') {
        center('*** VOIDED ***', true, true);
    }

    // The bill's numbers, the till, its location and the cashier.
    const rows: [string, string][] = [
        ['Bill no.', sale.invoiceNo],
        ['Date', receiptDate(sale.soldAt)],
    ];

    if (sale.till) {
        const name = (sale.till.name ?? '').trim();
        rows.push([
            'Till',
            (
                sale.till.code +
                (name !== '' && name !== sale.till.code ? ` ${name}` : '')
            ).trim(),
        ]);

        if (filled(sale.till.location)) {
            rows.push(['Location', String(sale.till.location)]);
        }
    }

    rows.push(['Cashier', sale.cashier]);
    rows.forEach(([label, value]) => row(label, value, 0, 0, false, false));
    rule();

    // The lines: an OPD bill's services, then one Medicines amount.
    const opd = (sale.billType ?? 'standard') === 'opd';
    const services = opd
        ? sale.items.filter((item) => !item.isOpdMedicine)
        : sale.items;
    const medicines = opd
        ? sale.items.filter((item) => item.isOpdMedicine)
        : [];
    const locations = [
        ...new Set(
            services
                .map((item) => item.location ?? null)
                .filter((location) => filled(location)),
        ),
    ];
    let location: string | null | undefined = null;

    for (const item of services) {
        if (locations.length > 1 && (item.location ?? null) !== location) {
            location = item.location ?? null;
            text(`-- ${location ?? 'Other'} --`, 0, 0, true);
        }

        itemised(item);
    }

    if (medicines.length > 0) {
        row('Medicines', money(sale.medicinesTotal ?? 0, false), 0, 0, true);

        if ((sale.opdReceiptMedicines ?? 'names') !== 'totals') {
            for (const item of medicines) {
                text(
                    itemName(item) + (fullyReturned(item) ? ' (RETURNED)' : ''),
                    2,
                    2,
                );
                text(quantity(item.quantity, item.unitName), 4, 2);
                details(item, 4);
            }
        }
    }

    rule();

    // Subtotal, discounts and tax only when there are any; the total; the exempt key.
    if (sale.discountTotal > 0 || taxOn) {
        row('Subtotal', money(sale.subtotal));
    }

    if (sale.discountTotal > 0) {
        row('Discount', money(-sale.discountTotal));
    }

    if (taxOn) {
        const rate = sale.taxRate
            .toFixed(2)
            .replace(/0+$/, '')
            .replace(/\.$/, '');
        row(
            `Tax ${rate}%${sale.pricesIncludeTax ? ' (incl.)' : ''}`,
            money(sale.taxTotal),
        );
    }

    row(`TOTAL ${shop.currency}`, money(sale.grandTotal), 0, 0, true);

    if (taxOn && sale.items.some((item) => item.taxExempt)) {
        text('E = tax exempt');
    }

    rule();

    for (const payment of sale.payments) {
        row(payment.method, money(payment.amount));

        if (filled(payment.reference)) {
            text(`Ref ${payment.reference}`, 2, 2);
        }
    }

    row('Change', money(sale.changeDue));

    if ((sale.refundedTotal ?? 0) > 0) {
        row('Refunded', money(-(sale.refundedTotal ?? 0)));
    }

    const footer = (shop.footer ?? '').trim();

    if (footer !== '') {
        rule();
        footer.split(/\r\n|\r|\n/).forEach((line) => center(line));
    }

    if (sale.reprint) {
        rule('=');
        center(
            `REPRINT ${receiptDate(String(sale.reprint.printedAt ?? ''))}`,
            true,
        );
        center(`by ${sale.reprint.printedBy}`);
    }

    return lines;
}
