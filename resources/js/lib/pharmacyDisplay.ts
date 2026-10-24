import { formatQuantity } from '@/lib/money';
import type { DrugSchedule, PosUnit } from '@/types/pos';

/**
 * Display helpers for medicines at the till (pharmacy shops). Nothing here
 * decides what may be sold: the cart and the server do.
 */

/** Expiry this close (days) is shown in amber at the till. */
export const EXPIRY_WARNING_DAYS = 90;

export const scheduleLabels: Record<DrugSchedule, string> = {
    otc: 'OTC',
    prescription_only: 'Rx',
    controlled: 'Controlled',
};

/** Today on this till, 'YYYY-MM-DD' (the browser's day). */
export function today(): string {
    return new Intl.DateTimeFormat('en-CA').format(new Date());
}

/** Whole days from `from` to `to` (both 'YYYY-MM-DD'). */
function daysBetween(from: string, to: string): number {
    return Math.round(
        (Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) /
            86_400_000,
    );
}

export type ExpiryTone = 'expired' | 'soon' | 'ok';

export function expiryTone(
    expiry: string | null | undefined,
    day: string = today(),
): ExpiryTone {
    if (!expiry) {
        return 'ok';
    }

    const days = daysBetween(day, expiry);

    return days < 0 ? 'expired' : days <= EXPIRY_WARNING_DAYS ? 'soon' : 'ok';
}

export const expiryClasses: Record<ExpiryTone, string> = {
    expired: 'font-semibold text-destructive',
    soon: 'font-semibold text-amber-600 dark:text-amber-400',
    ok: 'text-muted-foreground',
};

/**
 * Base units as the largest pack sold here plus loose units, e.g.
 * "2 × Box of 30 + 5 Tablet". Null when the item has no pack sold here
 * (show the plain quantity, as for any item).
 */
export function inPacks(
    stock: number,
    units: PosUnit[] | undefined,
    baseName: string,
): string | null {
    const pack = (units ?? [])
        .filter((unit) => unit.sellable && !unit.is_base && unit.factor > 1)
        .sort((a, b) => b.factor - a.factor)[0];

    if (pack === undefined || stock <= 0) {
        return null;
    }

    const packs = Math.floor(stock / pack.factor + 1e-9);
    const loose = Number((stock - packs * pack.factor).toFixed(3));
    const parts = [
        packs > 0 ? `${packs} × ${pack.name}` : null,
        loose > 0 ? `${formatQuantity(loose)} ${baseName}` : null,
    ].filter((part) => part !== null);

    return parts.join(' + ');
}

/** The base unit's name of an item with units, else `fallback` (the item's `unit`). */
export function baseUnitName(
    units: PosUnit[] | undefined,
    fallback: string,
): string {
    return units?.find((unit) => unit.is_base)?.name ?? fallback;
}
