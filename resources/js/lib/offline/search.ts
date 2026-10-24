import type { PosItem } from '@/types/pos';
import { db, getMeta, metaKeys } from './db';
import type { CatalogItem, OfflineShop } from './db';
import { nearestExpiry, saleDay } from './fefo';

/**
 * Offline item search over the local catalog, matching ItemSearchService and
 * Product::scopeSearch / scopeFitsVehicle on the server:
 *  - exact: a variant whose barcode or SKU equals the term (scanner + Enter),
 *    or with an active pack whose barcode does (`scanned_unit_id` = that pack)
 *  - results: variants of products whose name, part number or OEM numbers
 *    (or, for medicines, generic or brand name) contain the term, or with
 *    any variant whose SKU or barcode equals it,
 *    optionally only parts that fit a vehicle; sorted by SKU, at most 20.
 * Text matching ignores case, like the database collation.
 */
export const SEARCH_LIMIT = 20;

export type SearchQuery = {
    term: string;
    makeId: number | null;
    modelId: number | null;
    year: number | null;
};

let cache: CatalogItem[] | null = null;

/** Call after the catalog changes so the next search reads it again. */
export function forgetSearchCache(): void {
    cache = null;
}

async function allItems(): Promise<CatalogItem[]> {
    cache ??= await db.items.toArray();

    return cache;
}

export async function itemsById(
    ids: number[],
): Promise<Map<number, CatalogItem>> {
    const rows = await db.items.bulkGet(ids);

    return new Map(
        rows
            .filter((row): row is CatalogItem => row !== undefined)
            .map((row) => [row.variant_id, row]),
    );
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

/**
 * The item as the online search presents it. Its nearest expiry is worked
 * out again from the cached batches, which offline sales use up.
 */
function toPosItem(
    item: CatalogItem,
    scannedUnitId: number | null,
    shop: OfflineShop | null,
    day: string,
): PosItem {
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
        min_days_to_expiry: item.min_days_to_expiry,
        batches: item.batches,
        nearest_expiry:
            item.batches === undefined
                ? item.nearest_expiry
                : nearestExpiry(
                      item.batches,
                      day,
                      item.min_days_to_expiry ??
                          shop?.minDaysToExpiryForSale ??
                          0,
                  ),
        scanned_unit_id: scannedUnitId,
    };
}

export async function searchOffline(
    query: SearchQuery,
): Promise<{ exact: PosItem | null; results: PosItem[] }> {
    const term = query.term.trim();
    const lower = term.toLowerCase();
    const hasVehicle = query.makeId !== null || query.modelId !== null;

    if (term === '' && !hasVehicle) {
        return { exact: null, results: [] };
    }

    const [items, shop] = await Promise.all([
        allItems(),
        getMeta<OfflineShop>(metaKeys.shop),
    ]);
    const day = saleDay(
        shop?.timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone,
    );
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

    const results = items
        .filter((item) => matchesText(item) && fits(item, query))
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
