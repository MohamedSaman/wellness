import CatalogController from '@/actions/App/Http/Controllers/Pos/Sync/CatalogController';
import InvoiceBlockController from '@/actions/App/Http/Controllers/Pos/Sync/InvoiceBlockController';
import SaleSyncController from '@/actions/App/Http/Controllers/Pos/Sync/SaleSyncController';
import TillController from '@/actions/App/Http/Controllers/Pos/TillController';
import { ApiError, postJson, queryString } from '@/lib/http';
import type { VehicleMake } from '@/types/pos';
import { db, getMeta, metaKeys, setMeta, withBarcodes } from './db';
import type {
    CatalogItem,
    InvoiceNumbers,
    OfflineShop,
    OfflineTill,
    OutboxSale,
} from './db';
import { forgetSearchCache } from './search';

/**
 * Talks to the /pos/sync endpoints with the till's device token. These
 * requests never use the cashier's session, so they keep working across
 * sign-ins and are refused as soon as the owner revokes the till.
 */
const FULL_SYNC_EVERY_MS = 24 * 60 * 60 * 1000;
const UPLOAD_BATCH = 50;
const LOW_ON_INVOICE_NUMBERS = 50;
const MAX_BACKOFF_MS = 5 * 60 * 1000;

/** The till's token was revoked or the till disabled: stop until it is registered again. */
export class TillAccessError extends Error {}

type CatalogPage = {
    cursor: string;
    full: boolean;
    has_more: boolean;
    next_after: number | null;
    shop: OfflineShop;
    till: OfflineTill;
    items: CatalogItem[];
    deleted: number[];
    vehicles: VehicleMake[] | null;
};

type SyncResult = {
    uuid: string | null;
    status: 'synced' | 'duplicate' | 'rejected' | 'retry';
    invoice_no: string | null;
    needs_review: boolean;
    message: string | null;
};

export async function tokenFor(tillUuid: string): Promise<string | null> {
    return getMeta<string>(metaKeys.token(tillUuid));
}

async function tillRequest<T>(
    method: 'GET' | 'POST',
    url: string,
    tillUuid: string,
    body?: unknown,
): Promise<T> {
    const token = await tokenFor(tillUuid);

    if (token === null) {
        throw new TillAccessError('This till is not set up for offline sales.');
    }

    let response: Response;
    // For System health: the offline sales still waiting here, and this PC's clock.
    const waiting = await outboxCounts(tillUuid).catch(() => null);

    try {
        response = await fetch(url, {
            method,
            credentials: 'omit',
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
                'X-Till-Time': new Date().toISOString(),
                ...(waiting === null
                    ? {}
                    : {
                          'X-Till-Pending': String(
                              waiting.pending + waiting.rejected,
                          ),
                      }),
                ...(body === undefined
                    ? {}
                    : { 'Content-Type': 'application/json' }),
            },
            ...(body === undefined ? {} : { body: JSON.stringify(body) }),
        });
    } catch {
        throw new ApiError('Cannot reach the server.', 0);
    }

    const data = await response.json().catch(() => ({}));

    if (response.status === 401 || response.status === 403) {
        throw new TillAccessError(
            data.message ?? 'This till can no longer sync. Register it again.',
        );
    }

    if (!response.ok) {
        throw new ApiError(
            data.message ?? 'The server could not sync.',
            response.status,
        );
    }

    return data as T;
}

/**
 * Owner only: give this browser a device token for the till. A fresh start
 * for its offline invoice numbers too: the ones reserved before are dropped
 * (only a gap in the numbering), so a till registered again after a go-live
 * reset, which deletes its blocks on the server, never reuses one.
 */
export async function registerTill(tillUuid: string): Promise<void> {
    const { token } = await postJson<{ token: string }>(
        TillController.register.url(),
        { device: tillUuid },
    );

    await db.transaction('rw', db.meta, async () => {
        await setMeta(metaKeys.token(tillUuid), token);

        const numbers = await getMeta<InvoiceNumbers>(metaKeys.invoiceNumbers);

        if (numbers?.tillUuid === tillUuid) {
            await db.meta.delete(metaKeys.invoiceNumbers);
        }
    });
}

export async function forgetTill(tillUuid: string): Promise<void> {
    await db.meta.delete(metaKeys.token(tillUuid));
}

/**
 * Brings the local catalog up to date: only the changes since the last sync,
 * or everything once a day, for a new till, the first time, or when the
 * till's location changed (its stock, units and batches are that location's).
 * `locationId` is the till's location as the page knows it, when it does; the
 * catalog's own answer is checked too, so a moved till always starts over.
 * Returns the location the server says the till is at now (what the catalog
 * was synced for): the caller trusts it from then on, since a page opened
 * before the till was moved still names the old one.
 */
export async function syncCatalog(
    tillUuid: string,
    locationId: number | null = null,
): Promise<number | null> {
    const [cursor, catalogTill, lastFull, catalogLocation] = await Promise.all([
        getMeta<string>(metaKeys.cursor),
        getMeta<string>(metaKeys.catalogTill),
        getMeta<number>(metaKeys.lastFullSync),
        getMeta<number>(metaKeys.catalogLocation),
    ]);

    let full =
        cursor === null ||
        catalogTill !== tillUuid ||
        lastFull === null ||
        Date.now() - lastFull > FULL_SYNC_EVERY_MS ||
        (locationId !== null &&
            catalogLocation !== null &&
            catalogLocation !== locationId);

    let since = full ? null : cursor;
    const seen = new Set<number>();
    let after: number | null = null;
    let first: CatalogPage | null = null;

    do {
        const page: CatalogPage = await tillRequest<CatalogPage>(
            'GET',
            CatalogController.url() + queryString({ since, after }),
            tillUuid,
        );

        // Only changes since the last sync, but for another location: start again with everything.
        if (
            !full &&
            first === null &&
            (page.till.location_id ?? null) !== catalogLocation
        ) {
            full = true;
            since = null;
            continue;
        }

        first ??= page;

        await db.transaction('rw', db.items, db.meta, async () => {
            if (page.items.length > 0) {
                await db.items.bulkPut(page.items.map(withBarcodes));
            }

            if (page.deleted.length > 0) {
                await db.items.bulkDelete(page.deleted);
            }

            if (page.vehicles !== null) {
                await setMeta(metaKeys.vehicles, page.vehicles);
            }
        });

        page.items.forEach((item) => seen.add(item.variant_id));
        after = page.has_more ? page.next_after : null;
    } while (after !== null || first === null);

    await db.transaction('rw', db.items, db.meta, async () => {
        if (full) {
            // Anything the server did not send in a full sync can no longer be sold.
            const stale = (await db.items.toCollection().primaryKeys()).filter(
                (id) => !seen.has(id),
            );
            await db.items.bulkDelete(stale);
            await setMeta(metaKeys.lastFullSync, Date.now());
        }

        await setMeta(metaKeys.shop, first.shop);
        await setMeta(metaKeys.till, first.till);
        await setMeta(metaKeys.catalogTill, tillUuid);
        await setMeta(metaKeys.catalogLocation, first.till.location_id ?? null);
        await setMeta(metaKeys.cursor, first.cursor);
        await setMeta(metaKeys.lastCatalogSync, Date.now());
    });

    forgetSearchCache();

    return first.till.location_id ?? null;
}

function numbersLeft(numbers: InvoiceNumbers | null): number {
    if (numbers === null || numbers.next === null) {
        return 0;
    }

    const next = numbers.next;

    return numbers.ranges.reduce(
        (left, range) =>
            left + Math.max(0, range.last - Math.max(range.first, next) + 1),
        0,
    );
}

export async function invoiceNumbersLeft(tillUuid: string): Promise<number> {
    const numbers = await getMeta<InvoiceNumbers>(metaKeys.invoiceNumbers);

    return numbers?.tillUuid === tillUuid ? numbersLeft(numbers) : 0;
}

/** While online, keep a stock of reserved invoice numbers for offline sales. */
export async function topUpInvoiceNumbers(tillUuid: string): Promise<void> {
    if ((await invoiceNumbersLeft(tillUuid)) >= LOW_ON_INVOICE_NUMBERS) {
        return;
    }

    const { block } = await tillRequest<{
        block: { code: string; first: number; last: number };
    }>('POST', InvoiceBlockController.url(), tillUuid);

    await db.transaction('rw', db.meta, async () => {
        const current = await getMeta<InvoiceNumbers>(metaKeys.invoiceNumbers);
        const numbers: InvoiceNumbers =
            current?.tillUuid === tillUuid
                ? current
                : { tillUuid, code: block.code, ranges: [], next: null };

        numbers.code = block.code;
        numbers.ranges = [
            ...numbers.ranges.filter(
                (range) => numbers.next !== null && range.last >= numbers.next,
            ),
            { first: block.first, last: block.last },
        ];
        numbers.next ??= block.first;

        if (numbersLeft(numbers) === 0) {
            numbers.next = block.first;
        }

        await setMeta(metaKeys.invoiceNumbers, numbers);
    });
}

/**
 * Takes the next reserved invoice number. Must run inside a Dexie
 * transaction that includes db.meta, so the number and the sale are saved
 * together and no number is ever used twice.
 */
export async function takeInvoiceNumber(
    tillUuid: string,
    padding: number,
): Promise<string> {
    const numbers = await getMeta<InvoiceNumbers>(metaKeys.invoiceNumbers);

    if (numbers?.tillUuid !== tillUuid || numbersLeft(numbers) === 0) {
        throw new Error(
            'This till has no offline invoice numbers left. Reconnect to get more.',
        );
    }

    const next = numbers.next as number;
    const range =
        numbers.ranges.find((r) => next >= r.first && next <= r.last) ??
        numbers.ranges.find((r) => r.first > next);

    if (range === undefined) {
        throw new Error('This till has no offline invoice numbers left.');
    }

    const number = Math.max(next, range.first);
    numbers.next = number + 1;
    await setMeta(metaKeys.invoiceNumbers, numbers);

    return `${numbers.code}-${String(number).padStart(padding, '0')}`;
}

function backoff(attempts: number): number {
    const base = Math.min(MAX_BACKOFF_MS, 5000 * 2 ** Math.min(attempts, 10));

    return Date.now() + base + Math.floor(Math.random() * 1000);
}

/** Pending sales for the till, oldest first. */
export async function pendingSales(tillUuid: string): Promise<OutboxSale[]> {
    return db.outbox
        .where('[tillUuid+status]')
        .equals([tillUuid, 'pending'])
        .sortBy('seq');
}

/**
 * Uploads waiting sales in the order they were made. A sale leaves the
 * outbox only when the server confirms it (synced, or already recorded);
 * on any failure it stays and is retried with a growing delay. One tab at a
 * time uploads (Web Locks), though the server would ignore a repeat anyway.
 */
export async function uploadOutbox(
    tillUuid: string,
    { force = false }: { force?: boolean } = {},
): Promise<void> {
    const run = async () => {
        const due = (await pendingSales(tillUuid)).filter(
            (sale) => force || sale.nextAttemptAt <= Date.now(),
        );

        for (let i = 0; i < due.length; i += UPLOAD_BATCH) {
            const batch = due.slice(i, i + UPLOAD_BATCH);
            let results: SyncResult[];

            try {
                ({ results } = await tillRequest<{ results: SyncResult[] }>(
                    'POST',
                    SaleSyncController.url(),
                    tillUuid,
                    {
                        sales: batch.map((sale) => sale.payload),
                        // The till's clock now: the server measures how far it is out (§7.5).
                        device_time: new Date().toISOString(),
                    },
                ));
            } catch (error) {
                const message =
                    error instanceof Error ? error.message : 'Sync failed.';

                await Promise.all(
                    batch.map((sale) =>
                        db.outbox.update(sale.seq as number, {
                            attempts: sale.attempts + 1,
                            nextAttemptAt: backoff(sale.attempts),
                            lastError: message,
                        }),
                    ),
                );

                throw error;
            }

            await db.transaction('rw', db.outbox, async () => {
                for (const sale of batch) {
                    const result = results.find((r) => r.uuid === sale.uuid);

                    if (
                        result?.status === 'synced' ||
                        result?.status === 'duplicate'
                    ) {
                        await db.outbox.update(sale.seq as number, {
                            status: 'synced',
                            syncedAt: new Date().toISOString(),
                            serverInvoiceNo: result.invoice_no,
                            needsReview: result.needs_review,
                            lastError: null,
                        });
                    } else if (result?.status === 'rejected') {
                        await db.outbox.update(sale.seq as number, {
                            status: 'rejected',
                            lastError: result.message,
                        });
                    } else {
                        await db.outbox.update(sale.seq as number, {
                            attempts: sale.attempts + 1,
                            nextAttemptAt: backoff(sale.attempts),
                            lastError: result?.message ?? 'Not confirmed yet.',
                        });
                    }
                }
            });
        }
    };

    if (typeof navigator !== 'undefined' && navigator.locks) {
        await navigator.locks.request('pos-outbox-upload', run);
    } else {
        await run();
    }
}

/** Rejected sales go back in the queue when the cashier presses "Sync now". */
export async function retryRejected(tillUuid: string): Promise<void> {
    await db.outbox
        .where('[tillUuid+status]')
        .equals([tillUuid, 'rejected'])
        .modify({ status: 'pending', nextAttemptAt: 0 });
}

/** Synced sales are kept a week for reprinting, then removed from this browser. */
export async function pruneSynced(): Promise<void> {
    const cutoff = new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).toISOString();

    await db.outbox
        .where('status')
        .equals('synced')
        .filter((sale) => (sale.syncedAt ?? '') < cutoff)
        .delete();
}

export async function outboxCounts(
    tillUuid: string,
): Promise<{ pending: number; rejected: number }> {
    const [pending, rejected] = await Promise.all([
        db.outbox
            .where('[tillUuid+status]')
            .equals([tillUuid, 'pending'])
            .count(),
        db.outbox
            .where('[tillUuid+status]')
            .equals([tillUuid, 'rejected'])
            .count(),
    ]);

    return { pending, rejected };
}

/** Unsynced sales on this browser for any till (for the sign-out warning). */
export async function unsyncedSaleCount(): Promise<number> {
    return db.outbox.where('status').anyOf('pending', 'rejected').count();
}
