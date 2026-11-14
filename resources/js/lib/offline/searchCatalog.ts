import type { HeldBack, PosItem } from '@/types/pos';
import type { CatalogItem, OfflineShop } from './db';
import { nearestExpiry } from './fefo';

/**
 * The till's offline item search over its cached catalog, without IndexedDB
 * (search.ts reads the catalog and calls this), so the server's tests can
 * run it under node and compare it with ItemSearchService
 * (TillSearchOfflineParityTest). Mirrors ItemSearchService::search() and
 * Product::scopeSearch / scopeFitsVehicle:
 *  - exact: a variant whose barcode or SKU equals the term (scanner + Enter),
 *    or with an active pack whose barcode does (`scanned_unit_id` = that pack),
 *    found whatever the scope;
 *  - results: variants of products whose name, part number or OEM numbers
 *    (or, for medicines, generic or brand name) contain the term, or with
 *    any variant whose SKU or barcode equals it, optionally only parts that
 *    fit a vehicle; sorted by SKU, at most 20;
 *  - scope 'here' (search by location, docs/PHARMACY.md "Till search by
 *    location"): only items with stock above 0 at the till's location, and
 *    every item that does not track stock (services). The catalog holds the
 *    till's own location only, so 'here' and 'all' are the scopes offline;
 *    another location's stock needs the server.
 * Text matching ignores case, like the database collation.
 */
export const SEARCH_LIMIT = 20;

export type SearchScope = 'here' | 'all';

export type SearchQuery = {
    term: string;
    makeId: number | null;
    modelId: number | null;
    year: number | null;
    /** Missing = 'all' (a shop without search by location). */
    scope?: SearchScope;
};

export type SearchResult = { exact: PosItem | null; results: PosItem[] };

/** Quantity::normalize(): 3 decimal places. */
function normalize(value: number): number {
    return Math.round(value * 1000) / 1000;
}

function fits(item: CatalogItem, query: SearchQuery): boolean {
    if (query.modelId === null && query.makeId === null) {
        return true;
    }

    return item.fitments.some(
        (fitment) =>
            (query.modelId !== null
                ? fitment.model_id === query.modelId
                : fitment.make_id === query.makeId) &&
            (query.year === null ||
                ((fitment.year_from === null ||
                    fitment.year_from <= query.year) &&
                    (fitment.year_to === null ||
                        fitment.year_to >= query.year))),
    );
}

/** In this till's own stock: no stock tracking (a service), or stock above 0 here. */
export function inStockHere(item: PosItem): boolean {
    return !item.track_stock || (item.stock ?? 0) > 0;
}

/**
 * Why a batch-tracked item with stock here can't be sold from it on `day`,
 * by the batch rules (ItemSearchService::heldBack()): 'expired', 'blocked'
 * (quarantined or recalled), 'near_expiry' (past its stop-selling date) or
 * null. The cached batches are the ones the server could sell when it
 * synced, so a batch that has expired since counts as expired; with none
 * cached, the server's own reason stands.
 */
export function heldBack(
    item: PosItem,
    day: string,
    shopMinDays: number,
): HeldBack | null {
    if (!item.track_stock || !item.track_batches || (item.stock ?? 0) <= 0) {
        return null;
    }

    const batches = (item.batches ?? []).filter(
        (batch) => normalize(batch.quantity) > 0,
    );

    if (batches.length === 0) {
        return item.held_back ?? null;
    }

    if (
        !batches.some((batch) => batch.expiry === null || batch.expiry >= day)
    ) {
        return 'expired';
    }

    return nearestExpiry(
        batches,
        day,
        item.min_days_to_expiry ?? shopMinDays,
    ) === null
        ? 'near_expiry'
        : null;
}

/**
 * The item as the online search presents it. Its nearest expiry and held
 * back reason are worked out again from the cached batches, which offline
 * sales use up.
 */
export function toPosItem(
    item: CatalogItem,
    scannedUnitId: number | null,
    shop: OfflineShop | null,
    day: string,
): PosItem {
    const minDays = shop?.minDaysToExpiryForSale ?? 0;

    return {
        variant_id: item.variant_id,
        name: item.name,
        variant_name: item.variant_name,
        sku: item.sku,
        barcode: item.barcode,
        part_number: item.part_number,
        oem_numbers: item.oem_numbers,
        unit: item.unit,
        price: item.price,
        track_stock: item.track_stock,
        stock: item.stock,
        tax_category: item.tax_category ?? 'standard',
        drug: item.drug ?? null,
        units: item.units,
        sellable: item.sellable,
        whole_units_only: item.whole_units_only,
        track_batches: item.track_batches,
        allow_price_override: item.allow_price_override,
        price_min: item.price_min ?? null,
        price_max: item.price_max ?? null,
        ask_price: item.ask_price ?? false,
        min_days_to_expiry: item.min_days_to_expiry,
        batches: item.batches,
        nearest_expiry:
            item.batches === undefined
                ? item.nearest_expiry
                : nearestExpiry(
                      item.batches,
                      day,
                      item.min_days_to_expiry ?? minDays,
                  ),
        held_back: heldBack(item, day, minDays),
        scanned_unit_id: scannedUnitId,
    };
}

/** Searches `items` (the till's whole catalog) as the server would for this till on `day`. */
export function searchCatalog(
    items: CatalogItem[],
    query: SearchQuery,
    shop: OfflineShop | null,
    day: string,
): SearchResult {
    const term = query.term.trim();
    const lower = term.toLowerCase();
    const hasVehicle = query.makeId !== null || query.modelId !== null;

    if (term === '' && !hasVehicle) {
        return { exact: null, results: [] };
    }

    const isCode = (item: CatalogItem) =>
        item.sku.toLowerCase() === lower ||
        (item.barcode ?? '').toLowerCase() === lower;
    const scannedPack = (item: CatalogItem) =>
        (item.units ?? []).find(
            (unit) => (unit.barcode ?? '').toLowerCase() === lower,
        );

    const exact =
        term === ''
            ? null
            : (items
                  .filter((item) => isCode(item) || scannedPack(item))
                  .sort((a, b) => a.variant_id - b.variant_id)[0] ?? null);

    const productsWithCode = new Set(
        term === '' ? [] : items.filter(isCode).map((item) => item.product_id),
    );

    // Only medicines carry drug details, so this matches only in pharmacy shops, like the server.
    const matchesDrugName = (item: CatalogItem) =>
        item.drug != null &&
        ((item.drug.brand_name ?? '').toLowerCase().includes(lower) ||
            item.drug.generic_names.some((name) =>
                name.toLowerCase().includes(lower),
            ));

    const matchesText = (item: CatalogItem) =>
        term === '' ||
        item.name.toLowerCase().includes(lower) ||
        (item.part_number ?? '').toLowerCase().includes(lower) ||
        JSON.stringify(item.oem_numbers).toLowerCase().includes(lower) ||
        matchesDrugName(item) ||
        productsWithCode.has(item.product_id);

    const inScope = (item: CatalogItem) =>
        query.scope !== 'here' || inStockHere(item);

    const results = items
        .filter(
            (item) => matchesText(item) && fits(item, query) && inScope(item),
        )
        .sort((a, b) =>
            a.sku.toLowerCase() < b.sku.toLowerCase()
                ? -1
                : a.sku.toLowerCase() > b.sku.toLowerCase()
                  ? 1
                  : 0,
        )
        .slice(0, SEARCH_LIMIT);

    return {
        exact:
            exact === null
                ? null
                : toPosItem(
                      exact,
                      isCode(exact) ? null : (scannedPack(exact)?.id ?? null),
                      shop,
                      day,
                  ),
        results: results.map((item) => toPosItem(item, null, shop, day)),
    };
}
