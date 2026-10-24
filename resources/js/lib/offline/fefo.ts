import type { PosBatch, QuoteBatch } from '@/types/pos';

/**
 * The till's own first expiry, first out, for offline sales. A copy of the
 * server's rule (StockBatch::scopeSellableOn() and StockService::issue(),
 * docs/PHARMACY.md §6.2) over the batches the catalog cached for this till's
 * location, so the receipt can print the batch that left the shelf. The
 * server re-allocates on replay: it keeps this proposal where it is still
 * valid and flags any change, so a stale cache never loses a sale.
 *
 * Quantities are base units. A batch sells on the sale day (the shop's
 * timezone) when its expiry is empty or on or after the day plus the minimum
 * days (the medicine's own, else the shop's). Order: expiry, empty expiry
 * last, then the order the catalog sent (received_at, id), as the server.
 */
export type BatchProposal = { batch_id: number; quantity: number };

export type BatchPlan = {
    batches: QuoteBatch[];
    /** Base units no cached batch covers. Allowed offline: the server books it as unallocated. */
    shortfall: number;
};

/** One line to allocate, in cart order. */
export type FefoLine = {
    batches: PosBatch[];
    baseQuantity: number;
    /** Days before expiry the batch stops selling. */
    minDays: number;
    /** The cashier's batch picks (pos.batch_override), honoured first when valid. */
    picks?: BatchProposal[] | null;
};

/** Quantity::normalize(): 3 decimal places. */
function normalize(value: number): number {
    return Math.round(value * 1000) / 1000;
}

/** The calendar day in the shop's timezone, 'YYYY-MM-DD'. */
export function saleDay(timezone: string, at: Date = new Date()): string {
    try {
        // en-CA formats dates as YYYY-MM-DD.
        return new Intl.DateTimeFormat('en-CA', {
            timeZone: timezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(at);
    } catch {
        // An unknown timezone name: the browser's own day.
        return new Intl.DateTimeFormat('en-CA').format(at);
    }
}

/** 'YYYY-MM-DD' plus whole days (calendar arithmetic in UTC, no DST surprises). */
export function addDays(day: string, days: number): string {
    const date = new Date(`${day}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
}

/** Batches that may be sold on `day`, in FEFO order. Stable: equal expiries keep the catalog's order. */
export function sellableBatches(
    batches: PosBatch[],
    day: string,
    minDays: number,
): PosBatch[] {
    const earliest = addDays(day, minDays);

    return batches
        .map((batch, index) => ({ batch, index }))
        .filter(
            ({ batch }) =>
                normalize(batch.quantity) > 0 &&
                (batch.expiry === null || batch.expiry >= earliest),
        )
        .sort((a, b) => {
            if (a.batch.expiry !== b.batch.expiry) {
                if (a.batch.expiry === null) {
                    return 1;
                }

                if (b.batch.expiry === null) {
                    return -1;
                }

                return a.batch.expiry < b.batch.expiry ? -1 : 1;
            }

            return a.index - b.index;
        })
        .map(({ batch }) => batch);
}

/** The first batch that sells on `day`: what the till shows as the nearest expiry. */
export function nearestExpiry(
    batches: PosBatch[],
    day: string,
    minDays: number,
): string | null {
    return sellableBatches(batches, day, minDays)[0]?.expiry ?? null;
}

/**
 * Plans one line. Call it for each line in cart order with one `taken` map
 * for the whole cart (keyed by batch id): lines of the same item share it, so
 * a box and loose tablets of one medicine draw on the batches one after the
 * other, as the server allocates them under one lock.
 */
export function planLine(
    line: FefoLine,
    day: string,
    taken: Map<number, number>,
): BatchPlan {
    const available = sellableBatches(line.batches, day, line.minDays);
    const left = (batch: PosBatch) =>
        normalize(batch.quantity - (taken.get(batch.id) ?? 0));
    const pieces = new Map<number, number>();
    let remaining = normalize(line.baseQuantity);

    const take = (batch: PosBatch, quantity: number) => {
        taken.set(batch.id, normalize((taken.get(batch.id) ?? 0) + quantity));
        pieces.set(batch.id, normalize((pieces.get(batch.id) ?? 0) + quantity));
        remaining = normalize(remaining - quantity);
    };

    // Picks first: each is honoured whole when its batch sells today and holds the quantity.
    for (const pick of line.picks ?? []) {
        const batch = available.find((b) => b.id === pick.batch_id);
        const quantity = Math.min(normalize(pick.quantity), remaining);

        if (batch !== undefined && quantity > 0 && left(batch) >= quantity) {
            take(batch, quantity);
        }
    }

    for (const batch of available) {
        if (remaining <= 0) {
            break;
        }

        const quantity = Math.min(left(batch), remaining);

        if (quantity > 0) {
            take(batch, quantity);
        }
    }

    return {
        batches: [...pieces].map(([id, quantity]) => {
            const batch = available.find((b) => b.id === id) as PosBatch;

            return {
                batch_id: id,
                batch_no: batch.batch_no,
                expiry: batch.expiry,
                quantity,
            };
        }),
        shortfall: Math.max(0, remaining),
    };
}

/** The proposal as the offline payload sends it. */
export function toProposal(plan: BatchPlan): BatchProposal[] {
    return plan.batches.map((piece) => ({
        batch_id: piece.batch_id,
        quantity: piece.quantity,
    }));
}
