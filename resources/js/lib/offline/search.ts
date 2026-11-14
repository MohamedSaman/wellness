import { db, getMeta, metaKeys } from './db';
import type { CatalogItem, OfflineShop } from './db';
import { saleDay } from './fefo';
import { searchCatalog } from './searchCatalog';
import type { SearchQuery, SearchResult } from './searchCatalog';

/**
 * Offline item search: reads the till's cached catalog (IndexedDB) and
 * searches it with searchCatalog.ts, which mirrors ItemSearchService.
 */
export { SEARCH_LIMIT } from './searchCatalog';
export type { SearchQuery, SearchScope } from './searchCatalog';

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

export async function searchOffline(query: SearchQuery): Promise<SearchResult> {
    if (
        query.term.trim() === '' &&
        query.makeId === null &&
        query.modelId === null
    ) {
        return { exact: null, results: [] };
    }

    const [items, shop] = await Promise.all([
        allItems(),
        getMeta<OfflineShop>(metaKeys.shop),
    ]);
    const day = saleDay(
        shop?.timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone,
    );

    return searchCatalog(items, query, shop ?? null, day);
}
