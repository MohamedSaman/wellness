<script setup lang="ts">
import { Car, MapPin, Search, X } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import ItemSearchController from '@/actions/App/Http/Controllers/Pos/ItemSearchController';
import VehicleController from '@/actions/App/Http/Controllers/Pos/VehicleController';
import ScheduleBadge from '@/components/pos/ScheduleBadge.vue';
import { Button } from '@/components/ui/button';
import { useOfflinePos } from '@/composables/useOfflinePos';
import { defaultUnit } from '@/composables/usePosCart';
import { ApiError, getJson, queryString } from '@/lib/http';
import { formatMoney, formatQuantity } from '@/lib/money';
import { getMeta, metaKeys } from '@/lib/offline/db';
import { unitsOf } from '@/lib/offline/pricing';
import { searchOffline } from '@/lib/offline/search';
import { inStockHere } from '@/lib/offline/searchCatalog';
import {
    baseUnitName,
    expiryClasses,
    expiryTone,
    inPacks,
} from '@/lib/pharmacyDisplay';
import type { HeldBack, PosItem, PosLocation, VehicleMake } from '@/types/pos';

const props = defineProps<{
    deviceUuid: string;
    decimals: number;
    currency: string;
    /** The shop's vehicle_fitment module: without it there is no vehicle filter. */
    vehicleFitment: boolean;
    /** The pharmacy module: medicines show strength, form, schedule and expiry. */
    pharmacy?: boolean;
    /** The sale is an OPD bill: a stock item sold here may be added without a price. */
    opdBill?: boolean;
    /**
     * Search by location (docs/PHARMACY.md, "Till search by location"): two
     * or more selling locations. Off (retail): no picker, search as before.
     */
    locationSearch?: boolean;
    /** The selling locations, in order (never Quarantine). */
    locations?: PosLocation[];
    /** The till's own location: the only stock it sells from. */
    tillLocation?: PosLocation | null;
    /** May ask for stock from here (stock.request, at a till away from the default location). */
    canRequestStock?: boolean;
}>();

const emit = defineEmits<{
    add: [item: PosItem];
    /** Ask for this item from another location (the Request dialog), `fromId` preferred. */
    request: [item: PosItem, fromId: number | null];
}>();

type SearchResponse = { exact: PosItem | null; results: PosItem[] };

const offline = useOfflinePos();
const searchLocally = () =>
    offline.isOffline.value && offline.state.catalogReady;

/**
 * Where the search looks: this till's location (the default, again at every
 * new sale), another selling location, or all of them. Only items in this
 * location's stock can be added; the others are there to see and ask for.
 */
type Scope = 'here' | 'all' | number;

const scope = ref<Scope>('here');
const byLocation = computed(
    () => !!props.locationSearch && !!props.tillLocation,
);
const otherLocations = computed(() =>
    (props.locations ?? []).filter(
        (location) => location.id !== props.tillLocation?.id,
    ),
);
const scopes = computed<{ value: Scope; label: string }[]>(() => [
    { value: 'here', label: `This location (${props.tillLocation?.name})` },
    ...otherLocations.value.map((location) => ({
        value: location.id,
        label: location.name,
    })),
    { value: 'all', label: 'All locations' },
]);
/** The cached catalog holds this location only: the others need the server. */
const scopeDisabled = (value: Scope) =>
    value !== 'here' && offline.isOffline.value;
const scopeLabel = computed(
    () =>
        scopes.value.find((option) => option.value === scope.value)?.label ??
        '',
);

/** Alt+L: the next location this till can look at now. */
function cycleScope(): void {
    if (!byLocation.value) {
        return;
    }

    const options = scopes.value.filter(
        (option) => !scopeDisabled(option.value),
    );
    const at = options.findIndex((option) => option.value === scope.value);
    scope.value = options[(at + 1) % options.length]?.value ?? 'here';
}

function resetScope(): void {
    scope.value = 'here';
}

function scopeParam(): string | undefined {
    return byLocation.value ? String(scope.value) : undefined;
}

function searchOfflineNow(): Promise<SearchResponse> {
    // Offline the till knows only its own stock: "this location".
    if (scope.value !== 'here') {
        scope.value = 'here';
    }

    return searchOffline({
        term: term.value,
        makeId: makeId.value,
        modelId: modelId.value,
        year: year.value === '' ? null : Number(year.value),
        scope: byLocation.value ? 'here' : undefined,
    });
}

/** Online search; if the server cannot be reached, the local catalog answers instead. */
async function fetchResults(): Promise<SearchResponse> {
    if (searchLocally()) {
        return searchOfflineNow();
    }

    try {
        const response = await getJson<SearchResponse>(
            ItemSearchController.url() +
                queryString({
                    device: props.deviceUuid,
                    q: term.value.trim(),
                    make_id: makeId.value,
                    model_id: modelId.value,
                    year: year.value,
                    scope: scopeParam(),
                }),
        );
        offline.reportConnection(true);

        return response;
    } catch (error) {
        if (error instanceof ApiError && error.status === 0) {
            offline.reportConnection(false);

            if (offline.state.catalogReady) {
                return searchOfflineNow();
            }
        }

        throw error;
    }
}

const term = ref('');
const results = ref<PosItem[]>([]);
const highlighted = ref(-1);
const message = ref<string | null>(null);
/** Why the last item picked was not added (kept until the next pick or search). */
const notice = ref<string | null>(null);
/** The item the notice offers to ask another location for ("Request transfer"). */
const noticeRequest = ref<PosItem | null>(null);
/** Good news (a stock request sent), shown until the next pick or search. */
const info = ref<string | null>(null);
const searching = ref(false);
const input = ref<HTMLInputElement | null>(null);

const vehiclesOpen = ref(false);
const makes = ref<VehicleMake[]>([]);
const makeId = ref<number | null>(null);
const modelId = ref<number | null>(null);
const year = ref('');

const models = computed(
    () => makes.value.find((make) => make.id === makeId.value)?.models ?? [],
);
const vehicleActive = computed(() => makeId.value !== null);

let sequence = 0;
let timer: ReturnType<typeof setTimeout> | undefined;

function focus(): void {
    input.value?.focus();
    input.value?.select();
}

/** Shows why an item could not be added (the cart's refusal). */
function showNotice(text: string): void {
    notice.value = text;
    noticeRequest.value = null;
    info.value = null;
}

/** Shows that something worked (a stock request was sent). */
function showInfo(text: string): void {
    info.value = text;
    notice.value = null;
    noticeRequest.value = null;
}

defineExpose({ focus, showNotice, showInfo, cycleScope, resetScope });

async function run(scan: boolean): Promise<SearchResponse | null> {
    const mine = ++sequence;
    searching.value = true;
    message.value = null;

    try {
        const response = await fetchResults();

        if (mine !== sequence) {
            return null;
        }

        results.value = response.results;
        highlighted.value = -1;

        if (
            !scan &&
            response.results.length === 0 &&
            (term.value.trim() !== '' || vehicleActive.value)
        ) {
            message.value =
                byLocation.value && scope.value === 'here'
                    ? `Nothing in ${props.tillLocation?.name} stock matches. Alt+L to look at other locations.`
                    : 'No items found.';
        }

        return response;
    } catch (error) {
        if (mine === sequence) {
            message.value =
                error instanceof ApiError ? error.message : 'Search failed.';
        }

        return null;
    } finally {
        if (mine === sequence) {
            searching.value = false;
        }
    }
}

function schedule(): void {
    clearTimeout(timer);

    if (term.value.trim() === '' && !vehicleActive.value) {
        sequence++;
        results.value = [];
        message.value = null;
        searching.value = false;

        return;
    }

    timer = setTimeout(() => void run(false), 150);
}

watch([term, makeId, modelId, year, scope], schedule);
watch(term, (value) => {
    if (value.trim() !== '') {
        notice.value = null;
        noticeRequest.value = null;
        info.value = null;
    }
});
watch(offline.isOffline, (isOffline) => {
    if (isOffline) {
        resetScope();
    }
});
watch(makeId, () => {
    modelId.value = null;
});

onBeforeUnmount(() => clearTimeout(timer));

/**
 * Why the item can't be added at this till, or null. Not sellable = no unit
 * sold here, or none with a price; on an OPD bill a medicine (stock item)
 * needs no price of its own.
 */
function refusal(item: PosItem): string | null {
    if (viewOnly(item)) {
        return notHere(item);
    }

    if (item.sellable !== false) {
        return null;
    }

    if (!unitsOf(item).some((unit) => unit.sellable)) {
        return `${item.sku} is not sold at this till.`;
    }

    return props.opdBill && item.track_stock
        ? null
        : `${item.sku} has no price here and can't be sold.`;
}

function pick(item: PosItem): void {
    const reason = refusal(item);

    if (reason !== null) {
        notice.value = reason;
        noticeRequest.value = viewOnly(item) && canRequest(item) ? item : null;
        info.value = null;
        focus();

        return;
    }

    // Stock here that the batch rules hold back: say so; the cart decides as before.
    const held = heldBackText(item);
    notice.value = held === null ? null : `${item.sku}: ${held}.`;
    noticeRequest.value = null;
    info.value = null;
    term.value = '';
    results.value = [];
    message.value = null;
    emit('add', item);
    focus();
}

/**
 * Search by location: an item that tracks stock and has none at this
 * till's location can't be added here; it is there to see and ask for.
 */
function viewOnly(item: PosItem): boolean {
    return byLocation.value && !inStockHere(item);
}

function locationName(id: number): string {
    return (
        (props.locations ?? []).find((location) => location.id === id)?.name ??
        ''
    );
}

/** Other selling locations holding the item, most first. Online only. */
function heldElsewhere(
    item: PosItem,
): { id: number; name: string; stock: number }[] {
    return (item.stock_by_location ?? [])
        .filter(
            (level) => level.id !== props.tillLocation?.id && level.stock > 0,
        )
        .map((level) => ({ ...level, name: locationName(level.id) }))
        .sort((a, b) => b.stock - a.stock);
}

/** "Not in Doctor's Medicines stock · General Pharmacy has 120". */
function notHere(item: PosItem): string {
    const here = `Not in ${props.tillLocation?.name} stock`;

    if (item.stock_by_location === undefined) {
        return `${here} · other locations need a connection`;
    }

    const elsewhere = heldElsewhere(item);

    return elsewhere.length === 0
        ? `${here} · no other location has it`
        : `${here} · ${elsewhere.map((level) => `${level.name} has ${formatQuantity(level.stock)}`).join(' · ')}`;
}

/** Whether this till may ask another location for the item now. */
function canRequest(item: PosItem): boolean {
    return (
        !!props.canRequestStock &&
        !offline.isOffline.value &&
        heldElsewhere(item).length > 0
    );
}

function request(item: PosItem): void {
    const viewed = typeof scope.value === 'number' ? scope.value : null;
    const from = heldElsewhere(item).some((level) => level.id === viewed)
        ? viewed
        : null;

    emit('request', item, from);
}

const heldBackTexts: Record<HeldBack, string> = {
    expired: 'Only expired stock here',
    blocked: 'Only quarantined or recalled stock here',
    near_expiry: 'Only stock past its stop-selling date here',
};

/** Why stock here can't be sold today (the batch rules), or null. */
function heldBackText(item: PosItem): string | null {
    return item.held_back ? heldBackTexts[item.held_back] : null;
}

/** "GEN 120 · DOC 0". */
function stockByLocation(item: PosItem): string {
    return (item.stock_by_location ?? [])
        .map((level) => `${level.code} ${formatQuantity(level.stock)}`)
        .join(' · ');
}

/** Enter: a scanner types the code and presses Enter, so an exact barcode/SKU adds at once. */
async function onEnter(): Promise<void> {
    clearTimeout(timer);

    if (highlighted.value >= 0 && results.value[highlighted.value]) {
        pick(results.value[highlighted.value]);

        return;
    }

    if (term.value.trim() === '') {
        return;
    }

    const response = await run(true);

    if (response?.exact) {
        pick(response.exact);
    } else if (response && response.results.length === 0) {
        message.value = `Nothing matches "${term.value.trim()}".`;
    }
}

function move(delta: number): void {
    if (results.value.length === 0) {
        return;
    }

    highlighted.value =
        (highlighted.value + delta + results.value.length) %
        results.value.length;
}

async function toggleVehicles(): Promise<void> {
    vehiclesOpen.value = !vehiclesOpen.value;

    if (vehiclesOpen.value && makes.value.length === 0) {
        try {
            makes.value = searchLocally()
                ? ((await getMeta<VehicleMake[]>(metaKeys.vehicles)) ?? [])
                : (
                      await getJson<{ makes: VehicleMake[] }>(
                          VehicleController.url(),
                      )
                  ).makes;
        } catch {
            makes.value =
                (await getMeta<VehicleMake[]>(metaKeys.vehicles)) ?? [];

            if (makes.value.length === 0) {
                message.value = 'Could not load vehicles.';
            }
        }
    }
}

function clearVehicle(): void {
    makeId.value = null;
    modelId.value = null;
    year.value = '';
}

function isMedicine(item: PosItem): boolean {
    return !!props.pharmacy && !!item.drug;
}

/** Stock at this till; with packs sold here, as packs + loose units. */
function stockLabel(item: PosItem): string {
    if (!item.track_stock || item.stock === null) {
        return 'No stock tracking';
    }

    if (item.stock <= 0) {
        return byLocation.value ? 'None here' : 'Out of stock';
    }

    const base = baseUnitName(item.units, item.unit);
    const packs = inPacks(item.stock, item.units, base);

    if (packs !== null) {
        return `${packs} in stock`;
    }

    return isMedicine(item)
        ? `${formatQuantity(item.stock)} ${base} in stock`
        : `${formatQuantity(item.stock)} in stock`;
}

/**
 * The price shown: the base unit's, as always, unless the item is sold in a
 * pack by default here (then that pack's price, "/ Box of 30").
 */
function shownPrice(item: PosItem): string {
    if (item.sellable === false) {
        return unitsOf(item).some((unit) => unit.sellable)
            ? 'No price'
            : 'Not sold here';
    }

    const unit = item.units?.length ? defaultUnit(item) : null;

    if (unit && !unit.is_base) {
        return `${formatMoney(unit.price, props.decimals, props.currency)} / ${unit.name}`;
    }

    return formatMoney(item.price, props.decimals, props.currency);
}

/** "500 mg · Tablet · Paracetamol". */
function drugLine(item: PosItem): string {
    const drug = item.drug;

    if (!drug) {
        return '';
    }

    return [drug.strength, drug.dosage_form, drug.generic_names.join(' + ')]
        .filter((part) => part)
        .join(' · ');
}
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col gap-3">
        <div class="flex gap-2">
            <div class="relative flex-1">
                <Search
                    class="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-muted-foreground"
                />
                <input
                    ref="input"
                    v-model="term"
                    type="text"
                    inputmode="search"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    autofocus
                    data-test="pos-search"
                    :placeholder="
                        pharmacy
                            ? 'Scan barcode or search generic, brand, strength, SKU  (F2)'
                            : 'Scan barcode or search name, SKU, part number  (F2)'
                    "
                    class="h-14 w-full rounded-xl border bg-background pr-12 pl-12 text-lg shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    @keydown.enter.prevent="onEnter"
                    @keydown.down.prevent="move(1)"
                    @keydown.up.prevent="move(-1)"
                />
                <button
                    v-if="term"
                    type="button"
                    class="absolute top-1/2 right-3 grid size-9 -translate-y-1/2 place-items-center rounded-full text-muted-foreground hover:bg-accent"
                    aria-label="Clear search"
                    @click="
                        term = '';
                        focus();
                    "
                >
                    <X class="size-5" />
                </button>
            </div>
            <label v-if="byLocation" class="relative shrink-0">
                <span class="sr-only">Look in</span>
                <MapPin
                    class="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted-foreground"
                />
                <select
                    v-model="scope"
                    class="h-14 w-44 truncate rounded-xl border bg-background pr-2 pl-10 text-base shadow-xs xl:w-56"
                    title="Whose stock to look at (Alt+L)"
                    data-test="search-scope"
                    @change="focus()"
                >
                    <option
                        v-for="option in scopes"
                        :key="String(option.value)"
                        :value="option.value"
                        :disabled="scopeDisabled(option.value)"
                    >
                        {{ option.label
                        }}{{
                            scopeDisabled(option.value)
                                ? ' (needs a connection)'
                                : ''
                        }}
                    </option>
                </select>
            </label>
            <Button
                v-if="vehicleFitment"
                :variant="vehicleActive ? 'default' : 'outline'"
                class="h-14 px-4 text-base"
                data-test="vehicle-toggle"
                @click="toggleVehicles"
            >
                <Car class="size-5" /> Vehicle
            </Button>
        </div>

        <div
            v-if="vehicleFitment && vehiclesOpen"
            class="grid grid-cols-2 gap-2 rounded-xl border bg-card p-3 md:grid-cols-4"
            data-test="vehicle-filter"
        >
            <select
                v-model="makeId"
                class="h-12 rounded-lg border bg-background px-3 text-base"
                aria-label="Make"
            >
                <option :value="null">Any make</option>
                <option v-for="make in makes" :key="make.id" :value="make.id">
                    {{ make.name }}
                </option>
            </select>
            <select
                v-model="modelId"
                :disabled="makeId === null"
                class="h-12 rounded-lg border bg-background px-3 text-base disabled:opacity-50"
                aria-label="Model"
            >
                <option :value="null">Any model</option>
                <option
                    v-for="model in models"
                    :key="model.id"
                    :value="model.id"
                >
                    {{ model.name }}
                </option>
            </select>
            <input
                v-model="year"
                type="number"
                inputmode="numeric"
                min="1900"
                max="2100"
                placeholder="Year"
                class="h-12 rounded-lg border bg-background px-3 text-base"
                aria-label="Year"
            />
            <Button variant="ghost" class="h-12 text-base" @click="clearVehicle"
                >Clear vehicle</Button
            >
        </div>

        <div
            v-if="byLocation"
            class="flex flex-wrap items-center gap-2 text-sm"
            data-test="search-scope-chip"
        >
            <span
                class="inline-flex max-w-full items-center gap-1.5 rounded-full px-3 py-1 font-medium"
                :class="
                    scope === 'here'
                        ? 'bg-muted text-muted-foreground'
                        : 'bg-amber-500/15 text-amber-800 dark:text-amber-300'
                "
            >
                <MapPin class="size-4 shrink-0" />
                <span class="truncate"
                    >{{ scope === 'here' ? 'Selling from' : 'Looking at' }}
                    {{ scope === 'here' ? tillLocation?.name : scopeLabel
                    }}<template v-if="scope !== 'here'">
                        · view only, sells from
                        {{ tillLocation?.name }}</template
                    ></span
                >
                <button
                    v-if="scope !== 'here'"
                    type="button"
                    class="-mr-1 grid size-6 shrink-0 place-items-center rounded-full hover:bg-amber-500/20"
                    aria-label="Back to this location"
                    data-test="search-scope-reset"
                    @click="
                        resetScope();
                        focus();
                    "
                >
                    <X class="size-4" />
                </button>
            </span>
            <span class="text-xs text-muted-foreground">
                <template v-if="offline.isOffline.value"
                    >Other locations need a connection</template
                >
                <template v-else>Alt+L: other locations</template>
            </span>
        </div>

        <div
            v-if="notice"
            class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg bg-destructive/10 px-3 py-2 text-sm font-medium text-destructive"
            role="alert"
            data-test="search-notice"
        >
            <span class="min-w-0">{{ notice }}</span>
            <button
                v-if="noticeRequest"
                type="button"
                class="font-semibold underline"
                data-test="search-notice-request"
                @click="request(noticeRequest)"
            >
                Request transfer
            </button>
        </div>
        <p
            v-if="info"
            class="rounded-lg bg-emerald-500/10 px-3 py-2 text-sm font-medium text-emerald-700 dark:text-emerald-400"
            role="status"
            data-test="search-info"
        >
            {{ info }}
        </p>
        <p
            v-if="message"
            class="px-1 text-sm text-muted-foreground"
            role="status"
        >
            {{ message }}
        </p>

        <ul
            class="grid min-h-0 flex-1 content-start gap-2 overflow-y-auto pr-1"
            data-test="search-results"
        >
            <li
                v-for="(item, index) in results"
                :key="item.variant_id"
                class="grid gap-1"
            >
                <button
                    type="button"
                    class="flex min-h-16 w-full items-center justify-between gap-3 rounded-xl border bg-card p-3 text-left transition hover:bg-accent active:scale-[0.99]"
                    :class="{
                        'ring-2 ring-primary': index === highlighted,
                        'opacity-50': refusal(item) !== null,
                    }"
                    :aria-disabled="refusal(item) !== null"
                    data-test="search-result"
                    @click="pick(item)"
                >
                    <span class="min-w-0">
                        <span
                            class="flex items-center gap-2 text-base font-semibold"
                        >
                            <span class="truncate"
                                >{{ item.name
                                }}<template v-if="item.variant_name">
                                    · {{ item.variant_name }}</template
                                ></span
                            >
                            <ScheduleBadge
                                v-if="isMedicine(item) && item.drug"
                                :schedule="item.drug.schedule"
                            />
                        </span>
                        <span
                            v-if="isMedicine(item)"
                            class="block truncate text-sm"
                            data-test="drug-details"
                            >{{ drugLine(item) }}</span
                        >
                        <span
                            class="block truncate text-sm text-muted-foreground"
                            data-test="result-codes"
                        >
                            {{ item.sku
                            }}<template v-if="item.part_number">
                                · Part {{ item.part_number }}</template
                            >
                            <template v-if="item.oem_numbers.length">
                                · OEM
                                {{ item.oem_numbers.join(', ') }}</template
                            >
                        </span>
                    </span>
                    <span class="shrink-0 text-right">
                        <span
                            class="block font-bold tabular-nums"
                            :class="
                                item.sellable === false
                                    ? 'text-sm text-muted-foreground'
                                    : 'text-lg'
                            "
                            data-test="result-price"
                        >
                            {{ shownPrice(item) }}
                        </span>
                        <span
                            class="block text-xs"
                            :class="
                                item.track_stock && (item.stock ?? 0) <= 0
                                    ? 'font-semibold text-destructive'
                                    : 'text-muted-foreground'
                            "
                            data-test="result-stock"
                        >
                            {{ stockLabel(item) }}
                        </span>
                        <span
                            v-if="item.stock_by_location"
                            class="block text-xs text-muted-foreground tabular-nums"
                            data-test="result-locations"
                        >
                            {{ stockByLocation(item) }}
                        </span>
                        <span
                            v-if="isMedicine(item) && item.nearest_expiry"
                            class="block text-xs"
                            :class="
                                expiryClasses[expiryTone(item.nearest_expiry)]
                            "
                            data-test="result-expiry"
                        >
                            Exp {{ item.nearest_expiry }}
                        </span>
                        <span
                            v-if="heldBackText(item)"
                            class="block text-xs font-semibold text-destructive"
                            data-test="result-held-back"
                        >
                            {{ heldBackText(item) }}
                        </span>
                    </span>
                </button>
                <div
                    v-if="viewOnly(item)"
                    class="flex flex-wrap items-center justify-between gap-2 px-3 text-sm"
                    data-test="result-view-only"
                >
                    <span class="min-w-0 text-muted-foreground"
                        >Not in {{ tillLocation?.name }} stock: view only</span
                    >
                    <Button
                        v-if="canRequest(item)"
                        variant="outline"
                        size="sm"
                        class="h-9"
                        data-test="result-request"
                        @click="request(item)"
                    >
                        Request to {{ tillLocation?.name }}
                    </Button>
                </div>
            </li>
        </ul>
    </div>
</template>
