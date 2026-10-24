<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Printer,
    Search,
    Sparkles,
    Trash2,
    XCircle,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import PosController from '@/actions/App/Http/Controllers/Pos/PosController';
import StockRequestController from '@/actions/App/Http/Controllers/Pos/StockRequestController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ApiError, getJson, postJson, queryString } from '@/lib/http';
import type { StockRequestItemOption, StockRequestSummary } from '@/types/pos';

/**
 * Stock requests from the till (docs/PHARMACY.md §9, branch 10): a cashier
 * at the OPD asks General Pharmacy for stock in any unit of each item, and
 * sees the location's requests: what was asked for, sent and still to come.
 * General Pharmacy sends the stock from the admin panel. Online only.
 */
type Location = { id: number; name: string };
type Line = {
    item: StockRequestItemOption;
    unitId: number | null;
    quantity: number;
};

const props = defineProps<{
    shop: { name: string };
    locations: Location[];
    sources: Location[];
    defaultFrom: number;
    defaultFor: number | null;
    requests: StockRequestSummary[];
}>();

const forId = ref<number | null>(props.defaultFor);
const fromId = ref<number>(
    props.defaultFor === props.defaultFrom
        ? (props.sources.find((source) => source.id !== props.defaultFrom)
              ?.id ?? props.defaultFrom)
        : props.defaultFrom,
);
const sources = computed(() =>
    props.sources.filter((source) => source.id !== forId.value),
);
const fromName = computed(
    () =>
        props.sources.find((source) => source.id === fromId.value)?.name ?? '',
);

watch(forId, (value) => {
    if (value === fromId.value) {
        fromId.value = sources.value[0]?.id ?? props.defaultFrom;
    }
});

const query = ref('');
const results = ref<StockRequestItemOption[]>([]);
const searching = ref(false);
const lines = ref<Line[]>([]);
const note = ref('');
const error = ref<string | null>(null);
const done = ref<string | null>(null);
const busy = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;

watch(query, (value) => {
    clearTimeout(timer);

    if (value.trim().length < 2) {
        results.value = [];

        return;
    }

    timer = setTimeout(() => void search(value.trim()), 250);
});

async function search(term: string): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    searching.value = true;

    try {
        const response = await getJson<{ items: StockRequestItemOption[] }>(
            StockRequestController.items.url() +
                queryString({ q: term, for: forId.value, from: fromId.value }),
            controller.signal,
        );
        results.value = response.items;
        error.value = null;
    } catch (caught) {
        if (caught instanceof DOMException && caught.name === 'AbortError') {
            return;
        }

        error.value =
            caught instanceof ApiError ? caught.message : 'Search failed.';
    } finally {
        searching.value = false;
    }
}

function add(item: StockRequestItemOption): void {
    if (!lines.value.some((line) => line.item.id === item.id)) {
        lines.value.push({ item, unitId: null, quantity: 1 });
    }

    query.value = '';
    results.value = [];
}

function remove(index: number): void {
    lines.value.splice(index, 1);
}

/** What the line asks for in base units, to show next to a pack. */
function baseOf(line: Line): number {
    const unit = line.item.units.find(
        (candidate) => candidate.id === line.unitId,
    );

    return (unit?.factor ?? 1) * (Number(line.quantity) || 0);
}

const quantityText = (value: number) =>
    Number.isInteger(value)
        ? String(value)
        : value.toFixed(3).replace(/0+$/, '');

async function suggest(): Promise<void> {
    if (forId.value === null) {
        return;
    }

    busy.value = true;
    error.value = null;
    done.value = null;

    try {
        const response = await getJson<{ lines: Line[] }>(
            StockRequestController.suggest.url() +
                queryString({ for: forId.value, from: fromId.value }),
        );
        lines.value = response.lines;

        if (response.lines.length === 0) {
            done.value =
                'Nothing here is at or below its reorder level and short of its par level.';
        } else if (note.value === '') {
            note.value = 'Topping up to par levels';
        }
    } catch (caught) {
        error.value =
            caught instanceof ApiError
                ? caught.message
                : 'Could not work out the suggestion.';
    } finally {
        busy.value = false;
    }
}

async function send(): Promise<void> {
    if (forId.value === null || lines.value.length === 0) {
        return;
    }

    busy.value = true;
    error.value = null;
    done.value = null;

    try {
        const response = await postJson<{ request: { number: string } }>(
            StockRequestController.store.url(),
            {
                for: forId.value,
                from: fromId.value,
                note: note.value,
                lines: lines.value.map((line) => ({
                    variant_id: line.item.id,
                    unit_id: line.unitId,
                    quantity: line.quantity,
                })),
            },
        );
        done.value = `${response.request.number} sent to ${fromName.value}.`;
        lines.value = [];
        note.value = '';
        router.reload({ only: ['requests'] });
    } catch (caught) {
        error.value =
            caught instanceof ApiError
                ? caught.message
                : 'The request could not be sent.';
    } finally {
        busy.value = false;
    }
}

async function cancel(request: StockRequestSummary): Promise<void> {
    if (
        !window.confirm(
            `Cancel ${request.number}? Nothing will be sent for it.`,
        )
    ) {
        return;
    }

    try {
        await postJson(
            StockRequestController.cancel.url({ stockRequest: request.uuid }),
            {},
        );
        router.reload({ only: ['requests'] });
    } catch (caught) {
        error.value =
            caught instanceof ApiError
                ? caught.message
                : 'The request could not be cancelled.';
    }
}

const statusVariant = (status: StockRequestSummary['status']) =>
    status === 'requested'
        ? 'default'
        : status === 'partly_sent'
          ? 'secondary'
          : 'outline';

const selectClass =
    'h-11 w-full rounded-md border border-input bg-background px-3 text-base shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';
</script>

<template>
    <Head title="Stock requests" />

    <div class="min-h-dvh bg-background">
        <header
            class="flex min-h-14 flex-wrap items-center gap-3 border-b px-4 py-2"
        >
            <Button variant="ghost" class="h-11" as-child>
                <a :href="PosController.index.url()"
                    ><ArrowLeft class="size-4" /> Back to the till</a
                >
            </Button>
            <h1 class="text-lg font-bold">Stock requests</h1>
            <span class="text-sm text-muted-foreground">{{ shop.name }}</span>
        </header>

        <main class="mx-auto grid max-w-6xl gap-6 p-4 lg:grid-cols-2">
            <section class="flex flex-col gap-4" data-test="new-request">
                <h2 class="text-xl font-semibold">Ask for stock</h2>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex flex-col gap-1 text-sm font-medium">
                        For
                        <select
                            v-model.number="forId"
                            :class="selectClass"
                            data-test="request-for"
                        >
                            <option
                                v-for="location in locations"
                                :key="location.id"
                                :value="location.id"
                            >
                                {{ location.name }}
                            </option>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1 text-sm font-medium">
                        From
                        <select v-model.number="fromId" :class="selectClass">
                            <option
                                v-for="source in sources"
                                :key="source.id"
                                :value="source.id"
                            >
                                {{ source.name }}
                            </option>
                        </select>
                    </label>
                </div>

                <div class="flex gap-2">
                    <div class="relative flex-1">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            v-model="query"
                            class="h-11 pl-9 text-base"
                            placeholder="Search by name, SKU or barcode"
                            data-test="request-search"
                        />
                    </div>
                    <Button
                        variant="outline"
                        class="h-11"
                        :disabled="busy || forId === null"
                        title="Every item here at or below its reorder level, up to its par level"
                        @click="suggest"
                    >
                        <Sparkles class="size-4" /> From low stock
                    </Button>
                </div>

                <ul
                    v-if="results.length > 0"
                    class="divide-y rounded-md border"
                    data-test="request-results"
                >
                    <li v-for="item in results" :key="item.id">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left hover:bg-muted"
                            @click="add(item)"
                        >
                            <span>
                                <span class="font-medium">{{ item.name }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ item.sku }}</span
                                >
                            </span>
                            <span
                                class="text-right text-xs text-muted-foreground"
                            >
                                Here {{ quantityText(item.onHandHere) }} ·
                                {{ fromName }}
                                {{ quantityText(item.onHandThere) }}
                                {{ item.baseUnit }}
                            </span>
                        </button>
                    </li>
                </ul>
                <p v-else-if="searching" class="text-sm text-muted-foreground">
                    Searching…
                </p>

                <table v-if="lines.length > 0" class="w-full text-sm">
                    <thead
                        class="text-left text-xs text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="py-1">Item</th>
                            <th class="py-1">Unit</th>
                            <th class="py-1 text-right">Quantity</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(line, index) in lines"
                            :key="line.item.id"
                            class="border-t"
                            data-test="request-line"
                        >
                            <td class="py-2 pr-2">
                                <span class="font-medium">{{
                                    line.item.name
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >Here
                                    {{ quantityText(line.item.onHandHere) }}
                                    {{ line.item.baseUnit }}</span
                                >
                            </td>
                            <td class="py-2 pr-2">
                                <select
                                    v-model.number="line.unitId"
                                    :class="selectClass"
                                >
                                    <option
                                        v-for="unit in line.item.units"
                                        :key="unit.id"
                                        :value="unit.isBase ? null : unit.id"
                                    >
                                        {{ unit.name }}
                                    </option>
                                </select>
                            </td>
                            <td class="py-2 text-right">
                                <Input
                                    v-model.number="line.quantity"
                                    type="number"
                                    min="1"
                                    step="1"
                                    class="h-11 w-24 text-right text-base"
                                />
                                <span
                                    v-if="line.unitId !== null"
                                    class="block text-xs text-muted-foreground"
                                    >{{ quantityText(baseOf(line)) }}
                                    {{ line.item.baseUnit }}</span
                                >
                            </td>
                            <td class="py-2 pl-2">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    title="Remove"
                                    @click="remove(index)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="text-sm text-muted-foreground">
                    Search for the items you need, or start from low stock.
                </p>

                <textarea
                    v-model="note"
                    rows="2"
                    maxlength="500"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-base"
                    placeholder="Note for General Pharmacy (optional)"
                ></textarea>

                <p v-if="error" class="text-sm text-destructive" role="alert">
                    {{ error }}
                </p>
                <p
                    v-if="done"
                    class="text-sm text-emerald-600 dark:text-emerald-400"
                    data-test="request-done"
                >
                    {{ done }}
                </p>

                <Button
                    class="h-12 text-base"
                    :disabled="busy || lines.length === 0 || forId === null"
                    data-test="request-send"
                    @click="send"
                >
                    Send request to {{ fromName }}
                </Button>
            </section>

            <section class="flex flex-col gap-3" data-test="request-list">
                <h2 class="text-xl font-semibold">Requests</h2>
                <p
                    v-if="requests.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No requests yet.
                </p>
                <article
                    v-for="request in requests"
                    :key="request.uuid"
                    class="rounded-md border p-3"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold">{{ request.number }}</span>
                        <Badge :variant="statusVariant(request.status)">{{
                            request.statusLabel
                        }}</Badge>
                        <span class="text-xs text-muted-foreground"
                            >{{ request.requestedAt }} · for
                            {{ request.for }} from {{ request.from }}</span
                        >
                        <span class="ml-auto flex gap-1">
                            <Button variant="ghost" size="sm" as-child>
                                <a :href="request.printUrl" target="_blank"
                                    ><Printer class="size-4" /> Print</a
                                >
                            </Button>
                            <Button
                                v-if="request.canCancel"
                                variant="ghost"
                                size="sm"
                                @click="cancel(request)"
                            >
                                <XCircle class="size-4" /> Cancel
                            </Button>
                        </span>
                    </div>
                    <p v-if="request.note" class="mt-1 text-sm">
                        {{ request.note }}
                    </p>
                    <ul class="mt-2 space-y-1 text-sm">
                        <li
                            v-for="(item, index) in request.items"
                            :key="index"
                            class="flex justify-between gap-3"
                        >
                            <span>{{ item.name }} · {{ item.quantity }}</span>
                            <span
                                class="text-right text-xs text-muted-foreground"
                                >sent {{ item.sent }}, to come
                                {{ item.outstanding }} {{ item.baseUnit }}</span
                            >
                        </li>
                    </ul>
                </article>
            </section>
        </main>
    </div>
</template>
