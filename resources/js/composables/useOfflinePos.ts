import { computed, reactive } from 'vue';
import { ApiError } from '@/lib/http';
import { db, getMeta, metaKeys } from '@/lib/offline/db';
import type {
    OfflineReceipt,
    OfflineShop,
    OfflineTill,
    OutboxSale,
    SyncSalePayload,
} from '@/lib/offline/db';
import { unitsOf } from '@/lib/offline/pricing';
import { wipePatients } from '@/lib/offline/patients';
import { forgetSearchCache } from '@/lib/offline/search';
import {
    TillAccessError,
    cacheReceiptLogo,
    invoiceNumbersLeft,
    isDenied,
    outboxCounts,
    prepareTill,
    pruneSynced,
    receiptLogo,
    registerTill,
    retryRejected,
    setDenied,
    syncCatalog,
    takeInvoiceNumber,
    tokenFor,
    topUpInvoiceNumbers,
    unsyncedSaleCount,
    uploadOutbox,
} from '@/lib/offline/sync';

/**
 * Offline mode for the checkout screen, shared by every component.
 *
 * The till counts as offline when the browser says so or when a request to
 * the server just failed to connect. Offline, search and pricing use the
 * local catalog and sales go to the outbox. Every 5 minutes, and as soon as
 * the connection returns, the outbox is uploaded and the catalog refreshed.
 *
 * A till the owner approved prepares itself: open and online, with no button,
 * it takes its token, downloads the catalog and reserves invoice numbers. The
 * server decides (TillOfflineService): a till it refuses stays refused until
 * the page loads again, and one whose token it turned down stops selling
 * offline, its waiting sales kept.
 */
const CATALOG_EVERY_MS = 5 * 60 * 1000;
const OUTBOX_EVERY_MS = 20 * 1000;
/** The server's answer to a till preparing itself, as opposed to a failure worth retrying. */
const REFUSALS = [401, 403, 404, 409, 419, 422];

const state = reactive({
    enabled: false,
    /** The owner approved this till, as the page says. The server decides again when asked. */
    allowed: false,
    /** The server refused to prepare this till; not asked again until the page loads. */
    refused: false,
    /** The server turned this till's token down. Kept in this browser (metaKeys.denied). */
    denied: false,
    /** Taking the till's token. */
    preparing: false,
    tillUuid: null as string | null,
    browserOnline: typeof navigator === 'undefined' ? true : navigator.onLine,
    serverReachable: true,
    registered: false,
    accessError: null as string | null,
    catalogReady: false,
    pending: 0,
    rejected: 0,
    invoiceNumbersLeft: 0,
    syncing: false,
    lastSyncError: null as string | null,
    lastCatalogSync: null as number | null,
    cashier: {
        id: 0,
        name: '',
        unlimitedDiscount: false,
        canOverridePrice: false,
        canBatchOverride: false,
        canOpdBill: false,
    } as OfflineCashier,
    /** The till's location as the page says (start()); null = not known. */
    expectedLocationId: null as number | null,
    /** From the catalog: the location this till sells from, and whether it rings up OPD bills. */
    tillLocation: null as { id: number; code: string; name: string } | null,
    opdBilling: false,
});

/** The signed-in cashier, as the offline till needs them. */
export type OfflineCashier = {
    id: number;
    name: string;
    unlimitedDiscount: boolean;
    canOverridePrice?: boolean;
    canBatchOverride?: boolean;
    canOpdBill?: boolean;
};

let catalogTimer: ReturnType<typeof setInterval> | undefined;
let outboxTimer: ReturnType<typeof setInterval> | undefined;
let syncRun: Promise<void> | null = null;
let prepareRun: Promise<void> | null = null;

const isOffline = computed(
    () => !state.browserOnline || !state.serverReachable,
);
/** The till can sell without the server: registered, catalog downloaded, numbers reserved. */
const canSellOffline = computed(
    () =>
        state.enabled &&
        state.registered &&
        !state.denied &&
        state.catalogReady &&
        state.invoiceNumbersLeft > 0,
);

/**
 * What the till shows. `waiting`: approved but not ready when the connection
 * dropped, so only the connection coming back helps.
 */
export type OfflineReadiness =
    | 'ready'
    | 'preparing'
    | 'waiting'
    | 'unavailable';

const readiness = computed<OfflineReadiness>(() => {
    if (canSellOffline.value) {
        return 'ready';
    }

    if (
        state.refused ||
        !(state.allowed || (state.registered && !state.denied))
    ) {
        return 'unavailable';
    }

    return isOffline.value ? 'waiting' : 'preparing';
});

async function refreshCounts(): Promise<void> {
    if (state.tillUuid === null) {
        return;
    }

    const [counts, left, lastCatalog, items, location, till] =
        await Promise.all([
            outboxCounts(state.tillUuid),
            invoiceNumbersLeft(state.tillUuid),
            getMeta<number>(metaKeys.lastCatalogSync),
            getMeta<string>(metaKeys.catalogTill),
            getMeta<number>(metaKeys.catalogLocation),
            getMeta<OfflineTill>(metaKeys.till),
        ]);

    state.pending = counts.pending;
    state.rejected = counts.rejected;
    state.invoiceNumbersLeft = left;
    state.lastCatalogSync = lastCatalog;
    // A catalog for another location (the till was moved) can't price or allocate this till's sales.
    state.catalogReady =
        items === state.tillUuid &&
        (state.expectedLocationId === null ||
            location === null ||
            location === state.expectedLocationId);

    const current = till?.uuid === state.tillUuid ? till : null;
    state.tillLocation =
        current?.location_id === undefined
            ? null
            : {
                  id: current.location_id,
                  code: current.location_code ?? '',
                  name: current.location_name ?? '',
              };
    state.opdBilling = current?.opd_billing ?? false;
}

function noteFailure(error: unknown): void {
    if (error instanceof TillAccessError) {
        state.accessError = error.message;
        state.denied = true;

        if (state.tillUuid !== null) {
            void setDenied(state.tillUuid, true);
        }
    } else if (error instanceof ApiError && error.status === 0) {
        state.serverReachable = false;
    }

    state.lastSyncError =
        error instanceof Error ? error.message : 'Sync failed.';
}

/**
 * An approved till with no working token takes one. A refusal (not approved
 * any more, the till has another browser) stands until the page loads again;
 * a failed connection is tried again by the timers.
 */
function prepare(): Promise<void> {
    const tillUuid = state.tillUuid;

    if (
        !state.enabled ||
        !state.allowed ||
        state.refused ||
        tillUuid === null ||
        isOffline.value ||
        (state.registered && !state.denied)
    ) {
        return Promise.resolve();
    }

    prepareRun ??= (async () => {
        state.preparing = true;

        try {
            await prepareTill(tillUuid);

            if (state.tillUuid === tillUuid) {
                state.registered = true;
                state.denied = false;
                state.accessError = null;
            }
        } catch (error) {
            if (error instanceof ApiError && REFUSALS.includes(error.status)) {
                state.refused = true;
                state.lastSyncError = error.message;
                // Not approved (revoked) or set up on another browser: no patients here (branch 13).
                await wipePatients().catch(() => undefined);
            } else {
                noteFailure(error);
            }
        } finally {
            state.preparing = false;
            prepareRun = null;
        }
    })();

    return prepareRun;
}

/** Upload the outbox, then refresh the catalog and invoice numbers. */
async function runSync(options: { force?: boolean; catalog?: boolean } = {}) {
    const tillUuid = state.tillUuid;

    if (!state.enabled || tillUuid === null || !state.registered) {
        return;
    }

    if (syncRun !== null) {
        return syncRun;
    }

    state.syncing = true;
    syncRun = (async () => {
        try {
            if (options.force) {
                await retryRejected(tillUuid);
            }

            await uploadOutbox(tillUuid, { force: options.force });

            if (options.catalog !== false) {
                const location = await syncCatalog(
                    tillUuid,
                    state.expectedLocationId,
                );

                // The server's answer is where the till is now: a page opened before the
                // till was moved would otherwise full-sync every run and never sell offline.
                if (location !== null && state.tillUuid === tillUuid) {
                    state.expectedLocationId = location;
                }

                await topUpInvoiceNumbers(tillUuid);
                await pruneSynced();
                await cacheReceiptLogo().catch(() => undefined);
            }

            state.serverReachable = true;
            state.accessError = null;
            state.lastSyncError = null;

            if (state.denied) {
                state.denied = false;
                await setDenied(tillUuid, false);
            }
        } catch (error) {
            noteFailure(error);

            // Revoked, reset or its token refused: the till keeps no patients (branch 13),
            // before the token is replaced and the next (full) sync brings them back.
            if (error instanceof TillAccessError) {
                await wipePatients().catch(() => undefined);
            }
        } finally {
            await refreshCounts();
            state.syncing = false;
            syncRun = null;
        }
    })();

    return syncRun;
}

/** Takes the till's token if it needs one, then syncs. A token turned down during the sync is replaced at once. */
async function refresh(options: { catalog?: boolean } = {}): Promise<void> {
    await prepare();
    await runSync(options);

    if (state.denied && !state.refused) {
        await prepare();
        await runSync(options);
    }
}

function onBrowserOnline(): void {
    state.browserOnline = true;
    state.serverReachable = true;
    void refresh();
}

function onBrowserOffline(): void {
    state.browserOnline = false;
}

export function useOfflinePos() {
    /**
     * Called by the checkout screen once a till is chosen. `locationId` is
     * the till's location from the page (props.till.location.id): a catalog
     * synced for another location is not used, and the next sync is full.
     */
    async function start(
        tillUuid: string,
        enabled: boolean,
        cashier: OfflineCashier,
        locationId: number | null = null,
        allowed = false,
    ): Promise<void> {
        stop();
        state.enabled = enabled;
        state.allowed = allowed;
        state.refused = false;
        state.cashier = {
            canOverridePrice: false,
            canBatchOverride: false,
            canOpdBill: false,
            ...cashier,
        };
        state.expectedLocationId = locationId;
        state.tillUuid = tillUuid;
        state.registered = (await tokenFor(tillUuid)) !== null;
        state.denied = await isDenied(tillUuid);
        state.accessError = null;
        await refreshCounts();

        if (!enabled) {
            return;
        }

        window.addEventListener('online', onBrowserOnline);
        window.addEventListener('offline', onBrowserOffline);
        catalogTimer = setInterval(() => void refresh(), CATALOG_EVERY_MS);
        // Upload waiting sales soon after they are made; while the server is
        // unreachable this also checks whether the connection is back, and
        // while the till is getting ready it keeps at it.
        outboxTimer = setInterval(() => {
            if (!state.serverReachable || readiness.value === 'preparing') {
                void refresh();
            } else if (state.pending > 0) {
                void runSync({ catalog: false });
            }
        }, OUTBOX_EVERY_MS);

        void refresh();
    }

    function stop(): void {
        clearInterval(catalogTimer);
        clearInterval(outboxTimer);
        window.removeEventListener('online', onBrowserOnline);
        window.removeEventListener('offline', onBrowserOffline);
    }

    /** The "Refresh now" button: also retries sales the server refused before. */
    async function syncNow(): Promise<void> {
        state.serverReachable = true;
        await prepare();
        await runSync({ force: true });

        if (state.denied && !state.refused) {
            await prepare();
            await runSync({ force: true });
        }
    }

    async function register(): Promise<void> {
        if (state.tillUuid === null) {
            return;
        }

        await registerTill(state.tillUuid);
        state.registered = true;
        state.allowed = true;
        state.refused = false;
        state.denied = false;
        state.accessError = null;
        await runSync();
    }

    /** A request to the server failed to connect, or got through. */
    function reportConnection(reachable: boolean): void {
        const wasOffline = isOffline.value;
        state.serverReachable = reachable;

        if (reachable && wasOffline) {
            void refresh();
        }
    }

    async function shop(): Promise<OfflineShop | null> {
        return getMeta<OfflineShop>(metaKeys.shop);
    }

    /** The till as the catalog last described it (location, OPD billing). */
    async function till(): Promise<OfflineTill | null> {
        const saved = await getMeta<OfflineTill>(metaKeys.till);

        return saved?.uuid === state.tillUuid ? saved : null;
    }

    /**
     * Saves an offline sale: takes the next reserved invoice number and puts
     * the sale in the outbox in one transaction, then lowers local stock (in
     * base units) and each proposed batch, so the next sale sees what is left.
     */
    async function recordSale(
        build: (invoiceNo: string) => {
            payload: SyncSalePayload;
            receipt: OfflineReceipt;
        },
        padding: number,
    ): Promise<OutboxSale> {
        const tillUuid = state.tillUuid;

        if (tillUuid === null) {
            throw new Error('Choose a till first.');
        }

        const sale = await db.transaction(
            'rw',
            db.meta,
            db.outbox,
            db.items,
            async () => {
                const invoiceNo = await takeInvoiceNumber(tillUuid, padding);
                const { payload, receipt } = build(invoiceNo);
                const record: OutboxSale = {
                    uuid: payload.uuid,
                    tillUuid,
                    status: 'pending',
                    payload,
                    receipt,
                    createdAt: new Date().toISOString(),
                    attempts: 0,
                    nextAttemptAt: 0,
                    lastError: null,
                    syncedAt: null,
                    serverInvoiceNo: null,
                    needsReview: false,
                };

                await db.outbox.add(record);

                const round = (value: number) =>
                    Math.round(value * 1000) / 1000;

                for (const line of payload.lines) {
                    await db.items
                        .where('variant_id')
                        .equals(line.variant_id)
                        .modify((item) => {
                            const factor =
                                unitsOf(item).find((unit) =>
                                    line.unit_id == null
                                        ? unit.is_base
                                        : unit.id === line.unit_id,
                                )?.factor ?? 1;

                            if (item.track_stock && item.stock !== null) {
                                item.stock = round(
                                    item.stock - line.quantity * factor,
                                );
                            }

                            if (item.batches && line.batches) {
                                item.batches = item.batches
                                    .map((batch) => ({
                                        ...batch,
                                        quantity: round(
                                            batch.quantity -
                                                (line.batches ?? [])
                                                    .filter(
                                                        (piece) =>
                                                            piece.batch_id ===
                                                            batch.id,
                                                    )
                                                    .reduce(
                                                        (sum, piece) =>
                                                            sum +
                                                            piece.quantity,
                                                        0,
                                                    ),
                                        ),
                                    }))
                                    .filter((batch) => batch.quantity > 0);
                            }
                        });
                }

                return record;
            },
        );

        forgetSearchCache();
        await refreshCounts();
        void runSync({ catalog: false });

        return sale;
    }

    async function findSale(uuid: string): Promise<OutboxSale | undefined> {
        return db.outbox.where('uuid').equals(uuid).first();
    }

    return {
        state,
        isOffline,
        canSellOffline,
        readiness,
        start,
        stop,
        syncNow,
        register,
        reportConnection,
        shop,
        till,
        recordSale,
        findSale,
        receiptLogo,
        unsyncedSaleCount,
    };
}
