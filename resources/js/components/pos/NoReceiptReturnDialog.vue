<script setup lang="ts">
import { Printer, Search, Trash2, WifiOff } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import ItemSearchController from '@/actions/App/Http/Controllers/Pos/ItemSearchController';
import NoReceiptReturnController from '@/actions/App/Http/Controllers/Pos/NoReceiptReturnController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useOfflinePos } from '@/composables/useOfflinePos';
import { ApiError, getJson, postJson, queryString } from '@/lib/http';
import { formatMoney } from '@/lib/money';
import type { PosItem, PosShop, PosTill, PosUnit } from '@/types/pos';

/**
 * Return without receipt (pharmacy, returns.no_receipt; docs/PHARMACY.md,
 * branch 18): an item bought before the system went live, or without the
 * bill. Pick the item, unit and how many; the value starts at today's price
 * and may be changed; the stock goes to Quarantine, or back on sale here
 * with its batch and expiry. A reason is required. The refund is paid in
 * cash from this till's drawer and prints a REFUND – NO RECEIPT slip.
 * Online only.
 */
const props = defineProps<{ open: boolean; till: PosTill; shop: PosShop }>();
const emit = defineEmits<{
    'update:open': [value: boolean];
    recorded: [];
}>();

type Line = {
    key: number;
    item: PosItem;
    unitId: number | null;
    quantity: string;
    value: string;
    to: 'quarantine' | 'stock';
    batchNo: string;
    expiry: string;
};

const offlinePos = useOfflinePos();
const query = ref('');
const results = ref<PosItem[]>([]);
const searching = ref(false);
const lines = ref<Line[]>([]);
const reason = ref('');
const note = ref('');
const error = ref<string | null>(null);
const notice = ref<string | null>(null);
const saving = ref(false);
const quarantineName = ref<string | null>(null);
const searchInput = ref<HTMLInputElement | null>(null);
let nextKey = 1;
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;

const decimals = computed(() => props.shop.decimals);
const money = (minor: number) =>
    formatMoney(minor, props.shop.decimals, props.shop.currency);
const toInput = (minor: number | null) =>
    minor === null
        ? ''
        : (minor / 10 ** decimals.value).toFixed(decimals.value);
const unitsOf = (item: PosItem): PosUnit[] =>
    item.units?.length
        ? item.units
        : [
              {
                  id: 0,
                  name: item.unit,
                  factor: 1,
                  barcode: null,
                  price: item.price,
                  is_base: true,
                  is_default_sale: true,
                  sellable: true,
              },
          ];
const unitOf = (line: Line): PosUnit | undefined =>
    unitsOf(line.item).find((unit) => (unit.id || null) === line.unitId) ??
    unitsOf(line.item)[0];
const lineTotal = (line: Line): number => {
    const value = Number(line.value.replace(/,/g, ''));
    const quantity = Number(line.quantity);

    return Number.isFinite(value) && Number.isFinite(quantity)
        ? Math.round(value * 10 ** decimals.value * quantity)
        : 0;
};
const total = computed(() =>
    lines.value.reduce((sum, line) => sum + lineTotal(line), 0),
);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            controller?.abort();

            return;
        }

        query.value = '';
        results.value = [];
        lines.value = [];
        reason.value = '';
        note.value = '';
        error.value = null;
        notice.value = null;

        if (!offlinePos.isOffline.value) {
            void getJson<{ quarantine: string | null }>(
                NoReceiptReturnController.index.url() +
                    queryString({ device: props.till.uuid }),
            )
                .then((response) => {
                    quarantineName.value = response.quarantine;
                })
                .catch(() => {});
        }

        void nextTick(() => searchInput.value?.focus());
    },
);

watch(query, (value) => {
    clearTimeout(searchTimer);

    if (value.trim().length < 2) {
        results.value = [];

        return;
    }

    searchTimer = setTimeout(() => void runSearch(value.trim()), 250);
});

async function runSearch(term: string): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    searching.value = true;

    try {
        const response = await getJson<{
            exact: PosItem | null;
            results: PosItem[];
        }>(
            ItemSearchController.url() +
                queryString({ device: props.till.uuid, q: term, scope: 'all' }),
            controller.signal,
        );
        const found = [
            ...(response.exact ? [response.exact] : []),
            ...response.results.filter(
                (item) => item.variant_id !== response.exact?.variant_id,
            ),
        ];
        results.value = found.filter((item) => item.track_stock).slice(0, 8);
    } catch (caught) {
        if (!(caught instanceof DOMException && caught.name === 'AbortError')) {
            error.value =
                caught instanceof ApiError && caught.status !== 0
                    ? caught.message
                    : 'Searching needs a connection.';
        }
    } finally {
        searching.value = false;
    }
}

function add(item: PosItem): void {
    const unit =
        unitsOf(item).find((candidate) => candidate.is_default_sale) ??
        unitsOf(item)[0];
    lines.value.push({
        key: nextKey++,
        item,
        unitId: unit?.id || null,
        quantity: '1',
        value: toInput(unit?.price ?? null),
        to: 'quarantine',
        batchNo: '',
        expiry: '',
    });
    query.value = '';
    results.value = [];
}

function changeUnit(line: Line, unitId: number | null): void {
    line.unitId = unitId;
    line.value = toInput(unitOf(line)?.price ?? null);
}

async function save(): Promise<void> {
    if (saving.value) {
        return;
    }

    if (lines.value.length === 0) {
        error.value = 'Add the item that came back.';

        return;
    }

    if (reason.value.trim() === '') {
        error.value = 'Give the reason for the return.';

        return;
    }

    saving.value = true;
    error.value = null;
    notice.value = null;
    const slipWindow = window.open('', '_blank', 'width=420,height=720');

    try {
        const response = await postJson<{
            return: { number: string; refund_total: number };
            slip_url: string;
        }>(NoReceiptReturnController.store.url(), {
            device: props.till.uuid,
            reason: reason.value.trim(),
            note: note.value.trim() || null,
            lines: lines.value.map((line) => ({
                variant_id: line.item.variant_id,
                unit_id: line.unitId,
                quantity: Number(line.quantity),
                unit_value: line.value.trim().replace(/,/g, '') || null,
                to: line.to,
                batch_no: line.batchNo.trim() || null,
                expiry: line.expiry.trim() || null,
            })),
        });
        const url = `${response.slip_url}?print=1`;

        if (slipWindow) {
            slipWindow.location.href = url;
        } else {
            window.open(url, '_blank', 'width=420,height=720');
        }

        notice.value = `${response.return.number}: refund ${money(response.return.refund_total)} in cash. The slip is printing.`;
        lines.value = [];
        reason.value = '';
        note.value = '';
        emit('recorded');
    } catch (caught) {
        slipWindow?.close();
        error.value =
            caught instanceof ApiError && caught.status !== 0
                ? caught.message
                : 'The refund was not recorded. It needs a connection.';
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent
            class="max-h-[95dvh] overflow-y-auto sm:max-w-2xl"
            data-test="no-receipt-return"
        >
            <DialogHeader>
                <DialogTitle class="text-xl"
                    >Return without receipt</DialogTitle
                >
                <DialogDescription
                    >For items bought before the system or without the bill. The
                    refund is paid in cash from the
                    {{ till.code }} drawer.</DialogDescription
                >
            </DialogHeader>

            <p
                v-if="offlinePos.isOffline.value"
                class="flex items-start gap-2 rounded-lg bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-400"
            >
                <WifiOff class="mt-0.5 size-4 shrink-0" />
                <span>Offline: a return needs a connection.</span>
            </p>
            <p
                v-if="error"
                class="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive"
                role="alert"
                data-test="no-receipt-error"
            >
                {{ error }}
            </p>
            <p
                v-if="notice"
                class="rounded-lg bg-emerald-500/10 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-400"
                role="status"
                data-test="no-receipt-notice"
            >
                {{ notice }}
            </p>

            <form
                v-if="!offlinePos.isOffline.value"
                class="grid min-w-0 gap-3"
                @submit.prevent="save"
            >
                <div class="relative min-w-0">
                    <label class="grid min-w-0 gap-1 text-sm font-medium">
                        Item that came back
                        <span class="relative flex min-w-0 items-center">
                            <Search
                                class="pointer-events-none absolute left-3 size-4 text-muted-foreground"
                            />
                            <input
                                ref="searchInput"
                                v-model="query"
                                type="search"
                                autocomplete="off"
                                placeholder="Name, SKU or barcode"
                                class="h-11 w-full min-w-0 rounded-lg border bg-background pr-3 pl-9 text-base"
                                data-test="no-receipt-search"
                            />
                        </span>
                    </label>
                    <ul
                        v-if="results.length"
                        class="mt-1 max-h-56 divide-y overflow-y-auto rounded-lg border bg-background"
                    >
                        <li v-for="item in results" :key="item.variant_id">
                            <button
                                type="button"
                                class="flex w-full min-w-0 items-center gap-2 px-3 py-2 text-left hover:bg-muted"
                                data-test="no-receipt-result"
                                @click="add(item)"
                            >
                                <span class="min-w-0 flex-1 truncate">
                                    {{ item.name
                                    }}<template v-if="item.variant_name">
                                        · {{ item.variant_name }}</template
                                    >
                                </span>
                                <span
                                    class="shrink-0 text-xs text-muted-foreground"
                                    >{{ item.sku }}</span
                                >
                            </button>
                        </li>
                    </ul>
                    <p
                        v-else-if="searching"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        Searching...
                    </p>
                </div>

                <ul v-if="lines.length" class="grid min-w-0 gap-2">
                    <li
                        v-for="(line, index) in lines"
                        :key="line.key"
                        class="grid min-w-0 gap-2 rounded-lg border p-2"
                        data-test="no-receipt-line"
                    >
                        <div class="flex min-w-0 items-center gap-2">
                            <p class="min-w-0 flex-1 truncate font-semibold">
                                {{ line.item.name }}
                                <span
                                    class="text-xs font-normal text-muted-foreground"
                                    >{{ line.item.sku }}</span
                                >
                            </p>
                            <p class="shrink-0 font-semibold tabular-nums">
                                {{ money(lineTotal(line)) }}
                            </p>
                            <Button
                                type="button"
                                variant="ghost"
                                class="h-9 shrink-0"
                                :title="`Remove ${line.item.name}`"
                                @click="lines.splice(index, 1)"
                            >
                                <Trash2 class="size-4" />
                                <span class="sr-only">Remove</span>
                            </Button>
                        </div>
                        <div class="grid min-w-0 grid-cols-3 gap-2">
                            <label class="grid min-w-0 gap-1 text-xs">
                                How many
                                <input
                                    v-model="line.quantity"
                                    type="text"
                                    inputmode="decimal"
                                    class="h-10 min-w-0 rounded-lg border bg-background px-2 text-right text-base tabular-nums"
                                    data-test="no-receipt-quantity"
                                />
                            </label>
                            <label class="grid min-w-0 gap-1 text-xs">
                                Unit
                                <select
                                    :value="line.unitId ?? ''"
                                    class="h-10 min-w-0 rounded-lg border bg-background px-2 text-base"
                                    data-test="no-receipt-unit"
                                    @change="
                                        changeUnit(
                                            line,
                                            Number(
                                                (
                                                    $event.target as HTMLSelectElement
                                                ).value,
                                            ) || null,
                                        )
                                    "
                                >
                                    <option
                                        v-for="unit in unitsOf(line.item)"
                                        :key="unit.id"
                                        :value="unit.id || ''"
                                    >
                                        {{ unit.name }}
                                    </option>
                                </select>
                            </label>
                            <label class="grid min-w-0 gap-1 text-xs">
                                Value each ({{ shop.currency }})
                                <input
                                    v-model="line.value"
                                    type="text"
                                    inputmode="decimal"
                                    class="h-10 min-w-0 rounded-lg border bg-background px-2 text-right text-base tabular-nums"
                                    data-test="no-receipt-value"
                                />
                            </label>
                        </div>
                        <div
                            class="flex min-w-0 flex-wrap gap-1"
                            role="radiogroup"
                            aria-label="Where the stock goes"
                        >
                            <Button
                                type="button"
                                class="h-9"
                                :variant="
                                    line.to === 'quarantine'
                                        ? 'secondary'
                                        : 'outline'
                                "
                                :aria-pressed="line.to === 'quarantine'"
                                data-test="no-receipt-to-quarantine"
                                @click="line.to = 'quarantine'"
                            >
                                To {{ quarantineName ?? 'Quarantine' }}
                            </Button>
                            <Button
                                type="button"
                                class="h-9"
                                :variant="
                                    line.to === 'stock'
                                        ? 'secondary'
                                        : 'outline'
                                "
                                :aria-pressed="line.to === 'stock'"
                                data-test="no-receipt-to-stock"
                                @click="line.to = 'stock'"
                            >
                                Back on sale at
                                {{ till.location?.name ?? 'this till' }}
                            </Button>
                        </div>
                        <div
                            v-if="line.item.track_batches"
                            class="grid min-w-0 grid-cols-2 gap-2"
                        >
                            <label class="grid min-w-0 gap-1 text-xs">
                                Batch no.{{
                                    line.to === 'stock' ? '' : ' (if known)'
                                }}
                                <input
                                    v-model="line.batchNo"
                                    type="text"
                                    maxlength="50"
                                    autocomplete="off"
                                    class="h-10 min-w-0 rounded-lg border bg-background px-2 text-base"
                                    data-test="no-receipt-batch"
                                />
                            </label>
                            <label class="grid min-w-0 gap-1 text-xs">
                                Expiry (MM/YYYY)
                                <input
                                    v-model="line.expiry"
                                    type="text"
                                    maxlength="10"
                                    placeholder="03/2027"
                                    autocomplete="off"
                                    class="h-10 min-w-0 rounded-lg border bg-background px-2 text-base"
                                    data-test="no-receipt-expiry"
                                />
                            </label>
                        </div>
                    </li>
                </ul>

                <label class="grid min-w-0 gap-1 text-sm font-medium">
                    Reason
                    <input
                        v-model="reason"
                        type="text"
                        maxlength="255"
                        autocomplete="off"
                        placeholder="Bought before the system; changed to another medicine"
                        class="h-11 min-w-0 rounded-lg border bg-background px-3 text-base"
                        data-test="no-receipt-reason"
                    />
                </label>
                <label class="grid min-w-0 gap-1 text-sm font-medium">
                    Note (optional)
                    <input
                        v-model="note"
                        type="text"
                        maxlength="500"
                        autocomplete="off"
                        class="h-11 min-w-0 rounded-lg border bg-background px-3 text-base"
                        data-test="no-receipt-note"
                    />
                </label>

                <Button
                    type="submit"
                    class="h-12 text-base"
                    :disabled="saving || lines.length === 0"
                    data-test="no-receipt-save"
                >
                    <Printer class="size-4" />
                    {{
                        saving
                            ? 'Recording...'
                            : `Refund ${money(total)} in cash & print`
                    }}
                </Button>
            </form>
        </DialogContent>
    </Dialog>
</template>
