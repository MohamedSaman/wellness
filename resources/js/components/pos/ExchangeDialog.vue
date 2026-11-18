<script setup lang="ts">
import {
    ArrowLeft,
    Printer,
    Search,
    Trash2,
    UserRound,
    WifiOff,
} from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import ExchangeController from '@/actions/App/Http/Controllers/Pos/ExchangeController';
import ItemSearchController from '@/actions/App/Http/Controllers/Pos/ItemSearchController';
import RecentSalesController from '@/actions/App/Http/Controllers/Pos/RecentSalesController';
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
import { formatMoney, formatQuantity } from '@/lib/money';
import { reprintExchange } from '@/lib/receipt/reprint';
import type {
    CartExchange,
    CompletedSale,
    ExchangeBill,
    ExchangeBillLine,
    ExchangeSummary,
    PosItem,
    PosShop,
    PosTill,
    PosUnit,
    RecentSale,
    SalePatient,
} from '@/types/pos';

/**
 * Return / Exchange (Alt+X; pharmacy, pos.exchange; docs/PHARMACY.md
 * branch 19). Find the bill (invoice number, this till's recent sales, the
 * patient's bills), tick what came back and how many, where each goes
 * (Quarantine by default, or back on sale here), the reason and the doctor.
 * The server works out the credit. Then either Return only (the credit in
 * cash from this drawer, within the user's limit) or Exchange: the cart
 * takes the new items and the credit pays for them. Without a bill, the
 * owner enters the items as for a return without receipt. This shift lists
 * the till's returns and exchanges for reprints. Online only.
 */
const props = defineProps<{
    open: boolean;
    till: PosTill;
    shop: PosShop;
    /** The patient chosen for the cart: their bills are listed first. */
    patient: SalePatient | null;
}>();
const emit = defineEmits<{
    'update:open': [value: boolean];
    exchange: [exchange: CartExchange, patient: SalePatient | null];
    returned: [done: CompletedSale];
}>();

type Options = {
    exchanges: ExchangeSummary[];
    reasons: { value: string; label: string }[];
    doctors: { id: number; name: string }[];
    quarantine: string | null;
    here: string;
    cash_limit: number | null;
    max_days: number;
    can_late: boolean;
    can_no_receipt: boolean;
};

type Destination = 'quarantine' | 'stock';

type Ticked = {
    line: ExchangeBillLine;
    quantity: string;
    inBase: boolean;
    to: Destination;
};

type Loose = {
    key: number;
    item: PosItem;
    unitId: number | null;
    quantity: string;
    value: string;
    to: Destination;
    batchNo: string;
    expiry: string;
};

const offlinePos = useOfflinePos();
const tab = ref<'find' | 'shift'>('find');
const options = ref<Options | null>(null);
const query = ref('');
const found = ref<ExchangeBill[] | null>(null);
const patientBills = ref<ExchangeBill[] | null>(null);
const recent = ref<RecentSale[]>([]);
const bill = ref<ExchangeBill | null>(null);
const ticked = ref<Ticked[]>([]);
const withoutBill = ref(false);
const itemQuery = ref('');
const itemResults = ref<PosItem[]>([]);
const loose = ref<Loose[]>([]);
const reason = ref('');
const note = ref('');
const doctorId = ref<number | null>(null);
const credit = ref<number | null>(null);
const creditError = ref<string | null>(null);
const pricing = ref(false);
const error = ref<string | null>(null);
const saving = ref(false);
const confirmReturn = ref(false);
const reprinting = ref<string | null>(null);
/** One id per return started in this dialog: a double click records it once. */
const returnUuid = ref(crypto.randomUUID());
const searchInput = ref<HTMLInputElement | null>(null);
let nextKey = 1;
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let itemTimer: ReturnType<typeof setTimeout> | undefined;
let previewTimer: ReturnType<typeof setTimeout> | undefined;
let previewSequence = 0;

const money = (minor: number) =>
    formatMoney(minor, props.shop.decimals, props.shop.currency);
const toInput = (minor: number | null) =>
    minor === null
        ? ''
        : (minor / 10 ** props.shop.decimals).toFixed(props.shop.decimals);
const failure = (caught: unknown, fallback: string) =>
    caught instanceof ApiError && caught.status !== 0
        ? caught.message
        : fallback;

const quarantineName = computed(
    () => options.value?.quarantine ?? 'Quarantine',
);
const defaultDestination = computed<Destination>(() =>
    options.value !== null && options.value.quarantine === null
        ? 'stock'
        : 'quarantine',
);

/** What is coming back, as the server takes it. */
const returns = computed(() =>
    ticked.value
        .filter((row) => Number(row.quantity) > 0)
        .map((row) => ({
            sale_item_id: row.line.id,
            quantity: Number(row.quantity),
            in_base_units: row.inBase,
            to: row.to,
        })),
);
const noReceipt = computed(() =>
    loose.value
        .filter((row) => Number(row.quantity) > 0)
        .map((row) => ({
            variant_id: row.item.variant_id,
            unit_id: row.unitId,
            quantity: Number(row.quantity),
            unit_value: row.value.trim().replace(/,/g, '') || null,
            to: row.to,
            batch_no: row.batchNo.trim() || null,
            expiry: row.expiry.trim() || null,
        })),
);
const hasItems = computed(() =>
    withoutBill.value ? noReceipt.value.length > 0 : returns.value.length > 0,
);
const reasonMissing = computed(
    () =>
        reason.value === '' ||
        (reason.value === 'other' && note.value.trim() === ''),
);
const ready = computed(
    () =>
        hasItems.value &&
        !reasonMissing.value &&
        credit.value !== null &&
        creditError.value === null &&
        !pricing.value &&
        !saving.value,
);
const overLimit = computed(
    () =>
        options.value?.cash_limit !== null &&
        options.value?.cash_limit !== undefined &&
        credit.value !== null &&
        credit.value > options.value.cash_limit,
);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        tab.value = 'find';
        query.value = '';
        found.value = null;
        patientBills.value = null;
        bill.value = null;
        ticked.value = [];
        withoutBill.value = false;
        loose.value = [];
        itemQuery.value = '';
        itemResults.value = [];
        reason.value = '';
        note.value = '';
        doctorId.value = null;
        credit.value = null;
        creditError.value = null;
        error.value = null;
        confirmReturn.value = false;
        returnUuid.value = crypto.randomUUID();

        if (offlinePos.isOffline.value) {
            return;
        }

        void loadOptions();
        void loadRecent();

        if (props.patient !== null && !props.patient.isNew) {
            void loadPatientBills(props.patient.uuid);
        }

        void nextTick(() => searchInput.value?.focus());
    },
);

async function loadOptions(): Promise<void> {
    try {
        options.value = await getJson<Options>(
            ExchangeController.index.url() +
                queryString({ device: props.till.uuid }),
        );
        reason.value ||= options.value.reasons[0]?.value ?? '';
    } catch (caught) {
        error.value = failure(caught, 'Return / Exchange needs a connection.');
    }
}

async function loadRecent(): Promise<void> {
    try {
        recent.value = (
            await getJson<{ sales: RecentSale[] }>(
                RecentSalesController.url() +
                    queryString({ device: props.till.uuid }),
            )
        ).sales;
    } catch {
        recent.value = [];
    }
}

async function loadPatientBills(uuid: string): Promise<void> {
    try {
        patientBills.value = (
            await getJson<{ bills: ExchangeBill[] }>(
                ExchangeController.bills.url() +
                    queryString({ device: props.till.uuid, patient: uuid }),
            )
        ).bills;
    } catch {
        patientBills.value = [];
    }
}

watch(query, (value) => {
    clearTimeout(searchTimer);

    if (value.trim() === '') {
        found.value = null;

        return;
    }

    searchTimer = setTimeout(async () => {
        try {
            found.value = (
                await getJson<{ bills: ExchangeBill[] }>(
                    ExchangeController.bills.url() +
                        queryString({
                            device: props.till.uuid,
                            q: value.trim(),
                        }),
                )
            ).bills;
        } catch (caught) {
            error.value = failure(caught, 'Searching needs a connection.');
        }
    }, 250);
});

async function openBill(uuid: string): Promise<void> {
    error.value = null;
    credit.value = null;
    creditError.value = null;

    try {
        const response = await getJson<{
            bill: ExchangeBill & { lines: ExchangeBillLine[] };
        }>(
            ExchangeController.show.url({ sale: uuid }) +
                queryString({ device: props.till.uuid }),
        );
        bill.value = response.bill;
        ticked.value =
            response.bill.refusal === null
                ? response.bill.lines.map((line) => ({
                      line,
                      quantity: '',
                      inBase: false,
                      to:
                          line.stock_note !== null
                              ? 'quarantine'
                              : defaultDestination.value,
                  }))
                : [];
    } catch (caught) {
        error.value = failure(caught, 'The bill could not be opened.');
    }
}

function backToSearch(): void {
    bill.value = null;
    ticked.value = [];
    withoutBill.value = false;
    loose.value = [];
    credit.value = null;
    creditError.value = null;
    confirmReturn.value = false;
}

function toggle(row: Ticked): void {
    row.quantity =
        Number(row.quantity) > 0
            ? ''
            : String(
                  row.inBase ? row.line.returnable_base : row.line.returnable,
              );
}

function setUnit(row: Ticked, inBase: boolean): void {
    row.inBase = inBase;

    if (Number(row.quantity) > 0) {
        row.quantity = String(
            inBase ? row.line.returnable_base : row.line.returnable,
        );
    }
}

const unitLabel = (row: Ticked) =>
    row.inBase
        ? (row.line.base_unit_name ?? row.line.unit_name ?? '')
        : (row.line.unit_name ?? '');
const hasBaseUnit = (line: ExchangeBillLine) =>
    line.base_unit_name !== null &&
    line.unit_factor > 1 &&
    line.base_unit_name !== line.unit_name;

// Without a bill (owner): the items, as for a return without receipt.
watch(itemQuery, (value) => {
    clearTimeout(itemTimer);

    if (value.trim().length < 2) {
        itemResults.value = [];

        return;
    }

    itemTimer = setTimeout(async () => {
        try {
            const response = await getJson<{
                exact: PosItem | null;
                results: PosItem[];
            }>(
                ItemSearchController.url() +
                    queryString({
                        device: props.till.uuid,
                        q: value.trim(),
                        scope: 'all',
                    }),
            );
            itemResults.value = [
                ...(response.exact ? [response.exact] : []),
                ...response.results.filter(
                    (item) => item.variant_id !== response.exact?.variant_id,
                ),
            ]
                .filter((item) => item.track_stock)
                .slice(0, 8);
        } catch (caught) {
            error.value = failure(caught, 'Searching needs a connection.');
        }
    }, 250);
});

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

function addLoose(item: PosItem): void {
    const unit =
        unitsOf(item).find((candidate) => candidate.is_default_sale) ??
        unitsOf(item)[0];
    loose.value.push({
        key: nextKey++,
        item,
        unitId: unit?.id || null,
        quantity: '1',
        value: toInput(unit?.price ?? null),
        to: defaultDestination.value,
        batchNo: '',
        expiry: '',
    });
    itemQuery.value = '';
    itemResults.value = [];
}

function changeLooseUnit(row: Loose, unitId: number | null): void {
    row.unitId = unitId;
    row.value = toInput(
        unitsOf(row.item).find((unit) => (unit.id || null) === unitId)?.price ??
            null,
    );
}

// The credit, from the server, as the user ticks and types.
watch(
    () => JSON.stringify([returns.value, noReceipt.value, bill.value?.uuid]),
    () => {
        clearTimeout(previewTimer);
        confirmReturn.value = false;

        if (!hasItems.value) {
            credit.value = null;
            creditError.value = null;

            return;
        }

        pricing.value = true;
        previewTimer = setTimeout(() => void preview(), 300);
    },
);

async function preview(): Promise<void> {
    const mine = ++previewSequence;

    try {
        const response = await postJson<{ credit: number }>(
            ExchangeController.preview.url(),
            {
                device: props.till.uuid,
                sale: withoutBill.value ? null : (bill.value?.uuid ?? null),
                returns: withoutBill.value ? [] : returns.value,
                no_receipt: withoutBill.value ? noReceipt.value : [],
            },
        );

        if (mine === previewSequence) {
            credit.value = response.credit;
            creditError.value = null;
        }
    } catch (caught) {
        if (mine === previewSequence) {
            credit.value = null;
            creditError.value = failure(
                caught,
                'The credit could not be worked out. It needs a connection.',
            );
        }
    } finally {
        if (mine === previewSequence) {
            pricing.value = false;
        }
    }
}

/** What came back, in a few words, for the cart's banner. */
function summary(): string {
    const parts = withoutBill.value
        ? loose.value
              .filter((row) => Number(row.quantity) > 0)
              .map((row) => `${row.quantity} × ${row.item.name}`)
        : ticked.value
              .filter((row) => Number(row.quantity) > 0)
              .map(
                  (row) =>
                      `${formatQuantity(Number(row.quantity))} ${unitLabel(row)} ${row.line.description}`,
              );

    return parts.join(', ');
}

function cartExchange(): CartExchange {
    return {
        saleUuid: withoutBill.value ? null : (bill.value?.uuid ?? null),
        invoiceNo: withoutBill.value ? null : (bill.value?.invoice_no ?? null),
        credit: credit.value ?? 0,
        returns: withoutBill.value ? [] : returns.value,
        noReceipt: withoutBill.value ? noReceipt.value : [],
        summary: summary(),
        reason: reason.value,
        note: note.value,
        doctorId: doctorId.value,
    };
}

function exchange(): void {
    if (!ready.value) {
        return;
    }

    const patient = bill.value?.patient ?? null;

    emit(
        'exchange',
        cartExchange(),
        withoutBill.value
            ? props.patient
            : patient === null
              ? null
              : {
                    uuid: patient.uuid,
                    name: patient.name,
                    ageMonths: null,
                    isNew: false,
                },
    );
}

async function returnOnly(): Promise<void> {
    if (!ready.value) {
        return;
    }

    if (!confirmReturn.value) {
        confirmReturn.value = true;

        return;
    }

    saving.value = true;
    error.value = null;
    const body = cartExchange();

    try {
        const response = await postJson<{
            exchange: ExchangeSummary;
            receipt_url: string;
        }>(ExchangeController.store.url(), {
            device: props.till.uuid,
            uuid: returnUuid.value,
            sale: body.saleUuid,
            returns: body.returns,
            no_receipt: body.noReceipt,
            reason: body.reason,
            note: body.note.trim() || null,
            doctor_id: body.doctorId,
            ...(withoutBill.value && props.patient !== null
                ? {
                      patient: {
                          uuid: props.patient.uuid,
                          name: props.patient.name,
                          age_months: props.patient.ageMonths,
                      },
                  }
                : {}),
        });

        emit('returned', {
            uuid: response.exchange.uuid,
            invoice_no: response.exchange.number,
            grand_total: response.exchange.credit,
            change_due: 0,
            receipt_url: response.receipt_url,
            exchange: response.exchange,
        });
    } catch (caught) {
        error.value = failure(
            caught,
            'The return was not recorded. It needs a connection.',
        );
        confirmReturn.value = false;
    } finally {
        saving.value = false;
    }
}

async function reprint(row: ExchangeSummary): Promise<void> {
    if (reprinting.value !== null) {
        return;
    }

    reprinting.value = row.uuid;
    error.value = null;

    try {
        await reprintExchange(row.uuid, props.till.uuid);
    } catch (caught) {
        error.value = failure(caught, 'The receipt could not be reprinted.');
    } finally {
        reprinting.value = null;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent
            class="max-h-[95dvh] overflow-x-hidden overflow-y-auto sm:max-w-3xl"
            data-test="exchange-dialog"
        >
            <DialogHeader>
                <DialogTitle class="text-xl">Return / Exchange</DialogTitle>
                <DialogDescription
                    >Items brought back from a bill. Their credit pays for what
                    the doctor gives instead, or comes back in cash from the
                    {{ till.code }} drawer.</DialogDescription
                >
            </DialogHeader>

            <p
                v-if="offlinePos.isOffline.value"
                class="flex items-start gap-2 rounded-lg bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-400"
                data-test="exchange-offline"
            >
                <WifiOff class="mt-0.5 size-4 shrink-0" />
                <span>Offline: returns and exchanges need a connection.</span>
            </p>

            <template v-else>
                <div
                    class="flex min-w-0 gap-1 border-b"
                    role="tablist"
                    aria-label="Return / Exchange"
                >
                    <button
                        type="button"
                        role="tab"
                        class="-mb-px border-b-2 px-3 py-2 text-sm font-semibold"
                        :class="
                            tab === 'find'
                                ? 'border-primary'
                                : 'border-transparent text-muted-foreground'
                        "
                        :aria-selected="tab === 'find'"
                        data-test="exchange-tab-find"
                        @click="tab = 'find'"
                    >
                        Return or exchange
                    </button>
                    <button
                        type="button"
                        role="tab"
                        class="-mb-px border-b-2 px-3 py-2 text-sm font-semibold"
                        :class="
                            tab === 'shift'
                                ? 'border-primary'
                                : 'border-transparent text-muted-foreground'
                        "
                        :aria-selected="tab === 'shift'"
                        data-test="exchange-tab-shift"
                        @click="tab = 'shift'"
                    >
                        This shift ({{ options?.exchanges.length ?? 0 }})
                    </button>
                </div>

                <p
                    v-if="error"
                    class="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive"
                    role="alert"
                    data-test="exchange-error"
                >
                    {{ error }}
                </p>

                <!-- This shift: the till's returns and exchanges, to reprint. -->
                <div v-if="tab === 'shift'" class="min-w-0">
                    <p
                        v-if="!options || options.exchanges.length === 0"
                        class="py-6 text-center text-muted-foreground"
                    >
                        No returns or exchanges in this shift.
                    </p>
                    <ul v-else class="divide-y">
                        <li
                            v-for="row in options.exchanges"
                            :key="row.uuid"
                            class="flex min-w-0 items-center gap-3 py-2"
                            data-test="exchange-row"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-semibold">
                                    {{ row.number }} ·
                                    {{
                                        row.kind === 'exchange'
                                            ? 'Exchange'
                                            : 'Return'
                                    }}
                                    <span class="font-normal">
                                        {{ row.original_invoice_no
                                        }}<template v-if="row.new_invoice_no">
                                            → {{ row.new_invoice_no }}</template
                                        ></span
                                    >
                                </p>
                                <p
                                    class="truncate text-sm text-muted-foreground"
                                >
                                    {{ row.at }} · {{ row.reason }} ·
                                    {{ row.user }}
                                </p>
                            </div>
                            <span
                                class="shrink-0 text-right text-sm tabular-nums"
                            >
                                <template v-if="row.cash_refunded > 0"
                                    >Refund
                                    {{ money(row.cash_refunded) }}</template
                                >
                                <template v-else
                                    >Paid
                                    {{ money(row.patient_paid) }}</template
                                >
                            </span>
                            <Button
                                variant="outline"
                                class="h-10 shrink-0"
                                :disabled="reprinting !== null"
                                data-test="exchange-reprint"
                                @click="reprint(row)"
                            >
                                <Printer class="size-4" />
                                {{
                                    reprinting === row.uuid
                                        ? 'Reprinting...'
                                        : 'Reprint'
                                }}
                            </Button>
                        </li>
                    </ul>
                </div>

                <!-- Find the bill. -->
                <div
                    v-else-if="bill === null && !withoutBill"
                    class="grid min-w-0 gap-3"
                >
                    <label class="grid min-w-0 gap-1 text-sm font-medium">
                        Bill number
                        <span class="relative flex min-w-0 items-center">
                            <Search
                                class="pointer-events-none absolute left-3 size-4 text-muted-foreground"
                            />
                            <input
                                ref="searchInput"
                                v-model="query"
                                type="search"
                                autocomplete="off"
                                placeholder="Type or scan the invoice number"
                                class="h-12 w-full min-w-0 rounded-lg border bg-background pr-3 pl-9 text-base"
                                data-test="exchange-search"
                            />
                        </span>
                    </label>

                    <div class="max-h-[45dvh] min-w-0 overflow-y-auto">
                        <template v-if="found !== null">
                            <p
                                v-if="found.length === 0"
                                class="py-4 text-center text-muted-foreground"
                            >
                                No bill with that number.
                            </p>
                            <ul v-else class="divide-y">
                                <li v-for="row in found" :key="row.uuid">
                                    <button
                                        type="button"
                                        class="flex w-full min-w-0 items-center gap-3 px-2 py-2 text-left hover:bg-muted"
                                        data-test="exchange-bill"
                                        @click="openBill(row.uuid)"
                                    >
                                        <span class="min-w-0 flex-1">
                                            <span class="block font-semibold">{{
                                                row.invoice_no
                                            }}</span>
                                            <span
                                                class="block truncate text-sm text-muted-foreground"
                                                >{{ row.sold_at }} ·
                                                {{ row.till
                                                }}<template v-if="row.patient">
                                                    ·
                                                    {{
                                                        row.patient.name
                                                    }}</template
                                                ></span
                                            >
                                            <span
                                                v-if="row.refusal"
                                                class="block text-sm text-amber-700 dark:text-amber-400"
                                                >{{ row.refusal }}</span
                                            >
                                        </span>
                                        <span
                                            class="shrink-0 font-semibold tabular-nums"
                                            >{{ money(row.grand_total) }}</span
                                        >
                                    </button>
                                </li>
                            </ul>
                        </template>
                        <template v-else>
                            <template
                                v-if="patientBills && patientBills.length"
                            >
                                <p
                                    class="flex items-center gap-1 px-2 pt-1 text-sm font-semibold"
                                >
                                    <UserRound class="size-4" />
                                    Bills of {{ patient?.name }}
                                </p>
                                <ul class="divide-y">
                                    <li
                                        v-for="row in patientBills"
                                        :key="row.uuid"
                                    >
                                        <button
                                            type="button"
                                            class="flex w-full min-w-0 items-center gap-3 px-2 py-2 text-left hover:bg-muted"
                                            data-test="exchange-patient-bill"
                                            @click="openBill(row.uuid)"
                                        >
                                            <span class="min-w-0 flex-1">
                                                <span
                                                    class="block font-semibold"
                                                    >{{ row.invoice_no }}</span
                                                >
                                                <span
                                                    class="block truncate text-sm text-muted-foreground"
                                                    >{{ row.sold_at }} ·
                                                    {{ row.till }}</span
                                                >
                                                <span
                                                    v-if="row.refusal"
                                                    class="block text-sm text-amber-700 dark:text-amber-400"
                                                    >{{ row.refusal }}</span
                                                >
                                            </span>
                                            <span
                                                class="shrink-0 font-semibold tabular-nums"
                                                >{{
                                                    money(row.grand_total)
                                                }}</span
                                            >
                                        </button>
                                    </li>
                                </ul>
                            </template>
                            <p class="px-2 pt-2 text-sm font-semibold">
                                Recent sales on {{ till.code }}
                            </p>
                            <p
                                v-if="recent.length === 0"
                                class="px-2 py-3 text-sm text-muted-foreground"
                            >
                                No sales yet today.
                            </p>
                            <ul v-else class="divide-y">
                                <li v-for="row in recent" :key="row.uuid">
                                    <button
                                        type="button"
                                        class="flex w-full min-w-0 items-center gap-3 px-2 py-2 text-left hover:bg-muted"
                                        data-test="exchange-recent"
                                        @click="openBill(row.uuid)"
                                    >
                                        <span class="min-w-0 flex-1">
                                            <span class="block font-semibold">{{
                                                row.invoice_no
                                            }}</span>
                                            <span
                                                class="block truncate text-sm text-muted-foreground"
                                                >{{ row.sold_at }} ·
                                                {{ row.cashier }}</span
                                            >
                                        </span>
                                        <span
                                            class="shrink-0 font-semibold tabular-nums"
                                            >{{ money(row.grand_total) }}</span
                                        >
                                    </button>
                                </li>
                            </ul>
                        </template>
                    </div>

                    <button
                        v-if="options?.can_no_receipt"
                        type="button"
                        class="self-start text-sm font-medium underline underline-offset-4"
                        data-test="exchange-without-bill"
                        @click="withoutBill = true"
                    >
                        No bill? Take the items back without it (owner)
                    </button>
                </div>

                <!-- What came back. -->
                <div v-else class="grid min-w-0 gap-3">
                    <div class="flex min-w-0 items-center gap-2">
                        <Button
                            variant="ghost"
                            class="h-9 shrink-0"
                            data-test="exchange-back"
                            @click="backToSearch"
                        >
                            <ArrowLeft class="size-4" /> Back
                        </Button>
                        <p class="min-w-0 flex-1 truncate font-semibold">
                            <template v-if="withoutBill"
                                >Without a bill</template
                            >
                            <template v-else-if="bill"
                                >{{ bill.invoice_no }} · {{ bill.sold_at
                                }}<template v-if="bill.patient">
                                    · {{ bill.patient.name }}</template
                                ></template
                            >
                        </p>
                    </div>

                    <p
                        v-if="bill?.refusal"
                        class="rounded-lg bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-400"
                        role="alert"
                        data-test="exchange-refusal"
                    >
                        {{ bill.refusal }}
                    </p>

                    <!-- A bill's lines. -->
                    <ul
                        v-if="!withoutBill && ticked.length"
                        class="grid max-h-[40dvh] min-w-0 gap-2 overflow-y-auto"
                    >
                        <li
                            v-for="row in ticked"
                            :key="row.line.id"
                            class="grid min-w-0 gap-2 rounded-lg border p-2"
                            :class="{
                                'border-primary': Number(row.quantity) > 0,
                            }"
                            data-test="exchange-line"
                        >
                            <label class="flex min-w-0 items-start gap-2">
                                <input
                                    type="checkbox"
                                    class="mt-1 size-5 shrink-0"
                                    :checked="Number(row.quantity) > 0"
                                    data-test="exchange-line-tick"
                                    @change="toggle(row)"
                                />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-semibold"
                                        >{{ row.line.description }}
                                        <template
                                            v-if="
                                                row.line.strength &&
                                                !row.line.description.includes(
                                                    row.line.strength,
                                                )
                                            "
                                            >{{ row.line.strength }}</template
                                        ></span
                                    >
                                    <span
                                        class="block truncate text-sm text-muted-foreground"
                                        >Sold
                                        {{ formatQuantity(row.line.quantity) }}
                                        {{ row.line.unit_name }} · up to
                                        {{
                                            formatQuantity(row.line.returnable)
                                        }}
                                        can come back</span
                                    >
                                </span>
                            </label>
                            <div
                                v-if="Number(row.quantity) > 0"
                                class="flex min-w-0 flex-wrap items-center gap-2"
                            >
                                <input
                                    v-model="row.quantity"
                                    type="text"
                                    inputmode="decimal"
                                    :aria-label="`How many ${row.line.description}`"
                                    class="h-10 w-20 min-w-0 rounded-lg border bg-background px-2 text-right text-base tabular-nums"
                                    data-test="exchange-line-quantity"
                                />
                                <select
                                    v-if="hasBaseUnit(row.line)"
                                    :value="row.inBase ? 'base' : 'sold'"
                                    class="h-10 min-w-0 rounded-lg border bg-background px-2 text-base"
                                    aria-label="Unit"
                                    data-test="exchange-line-unit"
                                    @change="
                                        setUnit(
                                            row,
                                            ($event.target as HTMLSelectElement)
                                                .value === 'base',
                                        )
                                    "
                                >
                                    <option value="sold">
                                        {{ row.line.unit_name }}
                                    </option>
                                    <option value="base">
                                        {{ row.line.base_unit_name }}
                                    </option>
                                </select>
                                <span v-else class="text-sm">{{
                                    row.line.unit_name
                                }}</span>
                                <div
                                    v-if="row.line.track_stock"
                                    class="flex min-w-0 flex-wrap gap-1"
                                    role="radiogroup"
                                    aria-label="Where the stock goes"
                                >
                                    <Button
                                        type="button"
                                        class="h-9"
                                        :variant="
                                            row.to === 'quarantine'
                                                ? 'secondary'
                                                : 'outline'
                                        "
                                        :aria-pressed="row.to === 'quarantine'"
                                        :disabled="options?.quarantine === null"
                                        data-test="exchange-to-quarantine"
                                        @click="row.to = 'quarantine'"
                                    >
                                        To {{ quarantineName }}
                                    </Button>
                                    <Button
                                        type="button"
                                        class="h-9"
                                        :variant="
                                            row.to === 'stock'
                                                ? 'secondary'
                                                : 'outline'
                                        "
                                        :aria-pressed="row.to === 'stock'"
                                        :disabled="row.line.stock_note !== null"
                                        :title="row.line.stock_note ?? ''"
                                        data-test="exchange-to-stock"
                                        @click="row.to = 'stock'"
                                    >
                                        Back on sale
                                    </Button>
                                </div>
                            </div>
                            <p
                                v-if="
                                    Number(row.quantity) > 0 &&
                                    row.line.stock_note
                                "
                                class="text-xs text-muted-foreground"
                            >
                                {{ row.line.stock_note }}
                            </p>
                        </li>
                    </ul>
                    <p
                        v-else-if="!withoutBill && bill && !bill.refusal"
                        class="py-4 text-center text-muted-foreground"
                    >
                        Everything on this bill has already come back.
                    </p>

                    <!-- Without a bill (owner): the items. -->
                    <template v-if="withoutBill">
                        <label class="grid min-w-0 gap-1 text-sm font-medium">
                            Item that came back
                            <span class="relative flex min-w-0 items-center">
                                <Search
                                    class="pointer-events-none absolute left-3 size-4 text-muted-foreground"
                                />
                                <input
                                    v-model="itemQuery"
                                    type="search"
                                    autocomplete="off"
                                    placeholder="Name, SKU or barcode"
                                    class="h-11 w-full min-w-0 rounded-lg border bg-background pr-3 pl-9 text-base"
                                    data-test="exchange-item-search"
                                />
                            </span>
                        </label>
                        <ul
                            v-if="itemResults.length"
                            class="max-h-48 min-w-0 divide-y overflow-y-auto rounded-lg border"
                        >
                            <li
                                v-for="item in itemResults"
                                :key="item.variant_id"
                            >
                                <button
                                    type="button"
                                    class="flex w-full min-w-0 items-center gap-2 px-3 py-2 text-left hover:bg-muted"
                                    data-test="exchange-item-result"
                                    @click="addLoose(item)"
                                >
                                    <span class="min-w-0 flex-1 truncate">{{
                                        item.name
                                    }}</span>
                                    <span
                                        class="shrink-0 text-xs text-muted-foreground"
                                        >{{ item.sku }}</span
                                    >
                                </button>
                            </li>
                        </ul>
                        <ul
                            v-if="loose.length"
                            class="grid max-h-[40dvh] min-w-0 gap-2 overflow-y-auto"
                        >
                            <li
                                v-for="(row, index) in loose"
                                :key="row.key"
                                class="grid min-w-0 gap-2 rounded-lg border p-2"
                                data-test="exchange-loose-line"
                            >
                                <div class="flex min-w-0 items-center gap-2">
                                    <p
                                        class="min-w-0 flex-1 truncate font-semibold"
                                    >
                                        {{ row.item.name }}
                                    </p>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        class="h-9 shrink-0"
                                        :title="`Remove ${row.item.name}`"
                                        @click="loose.splice(index, 1)"
                                    >
                                        <Trash2 class="size-4" />
                                        <span class="sr-only">Remove</span>
                                    </Button>
                                </div>
                                <div class="grid min-w-0 grid-cols-3 gap-2">
                                    <label class="grid min-w-0 gap-1 text-xs">
                                        How many
                                        <input
                                            v-model="row.quantity"
                                            type="text"
                                            inputmode="decimal"
                                            class="h-10 min-w-0 rounded-lg border bg-background px-2 text-right text-base tabular-nums"
                                        />
                                    </label>
                                    <label class="grid min-w-0 gap-1 text-xs">
                                        Unit
                                        <select
                                            :value="row.unitId ?? ''"
                                            class="h-10 min-w-0 rounded-lg border bg-background px-2 text-base"
                                            @change="
                                                changeLooseUnit(
                                                    row,
                                                    Number(
                                                        (
                                                            $event.target as HTMLSelectElement
                                                        ).value,
                                                    ) || null,
                                                )
                                            "
                                        >
                                            <option
                                                v-for="unit in unitsOf(
                                                    row.item,
                                                )"
                                                :key="unit.id"
                                                :value="unit.id || ''"
                                            >
                                                {{ unit.name }}
                                            </option>
                                        </select>
                                    </label>
                                    <label class="grid min-w-0 gap-1 text-xs">
                                        Value each
                                        <input
                                            v-model="row.value"
                                            type="text"
                                            inputmode="decimal"
                                            class="h-10 min-w-0 rounded-lg border bg-background px-2 text-right text-base tabular-nums"
                                        />
                                    </label>
                                </div>
                                <div class="flex min-w-0 flex-wrap gap-1">
                                    <Button
                                        type="button"
                                        class="h-9"
                                        :variant="
                                            row.to === 'quarantine'
                                                ? 'secondary'
                                                : 'outline'
                                        "
                                        @click="row.to = 'quarantine'"
                                    >
                                        To {{ quarantineName }}
                                    </Button>
                                    <Button
                                        type="button"
                                        class="h-9"
                                        :variant="
                                            row.to === 'stock'
                                                ? 'secondary'
                                                : 'outline'
                                        "
                                        @click="row.to = 'stock'"
                                    >
                                        Back on sale
                                    </Button>
                                </div>
                                <div
                                    v-if="row.item.track_batches"
                                    class="grid min-w-0 grid-cols-2 gap-2"
                                >
                                    <label class="grid min-w-0 gap-1 text-xs">
                                        Batch no.
                                        <input
                                            v-model="row.batchNo"
                                            type="text"
                                            maxlength="50"
                                            autocomplete="off"
                                            class="h-10 min-w-0 rounded-lg border bg-background px-2 text-base"
                                        />
                                    </label>
                                    <label class="grid min-w-0 gap-1 text-xs">
                                        Expiry (MM/YYYY)
                                        <input
                                            v-model="row.expiry"
                                            type="text"
                                            maxlength="10"
                                            placeholder="03/2027"
                                            autocomplete="off"
                                            class="h-10 min-w-0 rounded-lg border bg-background px-2 text-base"
                                        />
                                    </label>
                                </div>
                            </li>
                        </ul>
                    </template>

                    <template v-if="ticked.length || withoutBill">
                        <div class="grid min-w-0 gap-2 sm:grid-cols-2">
                            <label
                                class="grid min-w-0 gap-1 text-sm font-medium"
                            >
                                Reason
                                <select
                                    v-model="reason"
                                    class="h-11 min-w-0 rounded-lg border bg-background px-2 text-base"
                                    data-test="exchange-reason"
                                >
                                    <option
                                        v-for="option in options?.reasons ?? []"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </select>
                            </label>
                            <label
                                class="grid min-w-0 gap-1 text-sm font-medium"
                            >
                                Doctor (optional)
                                <select
                                    v-model="doctorId"
                                    class="h-11 min-w-0 rounded-lg border bg-background px-2 text-base"
                                    data-test="exchange-doctor"
                                >
                                    <option :value="null">-</option>
                                    <option
                                        v-for="doctor in options?.doctors ?? []"
                                        :key="doctor.id"
                                        :value="doctor.id"
                                    >
                                        {{ doctor.name }}
                                    </option>
                                </select>
                            </label>
                        </div>
                        <label class="grid min-w-0 gap-1 text-sm font-medium">
                            Note{{
                                reason === 'other'
                                    ? ' (required)'
                                    : ' (optional)'
                            }}
                            <input
                                v-model="note"
                                type="text"
                                maxlength="500"
                                autocomplete="off"
                                class="h-11 min-w-0 rounded-lg border bg-background px-3 text-base"
                                data-test="exchange-note"
                            />
                        </label>

                        <div
                            class="flex min-w-0 items-baseline justify-between gap-3 rounded-xl bg-muted p-3"
                        >
                            <span class="text-muted-foreground"
                                >Return credit</span
                            >
                            <span
                                class="text-2xl font-extrabold tabular-nums"
                                data-test="exchange-credit"
                                >{{
                                    pricing
                                        ? '…'
                                        : credit === null
                                          ? '-'
                                          : money(credit)
                                }}</span
                            >
                        </div>
                        <p
                            v-if="creditError"
                            class="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive"
                            role="alert"
                            data-test="exchange-credit-error"
                        >
                            {{ creditError }}
                        </p>
                        <p
                            v-if="overLimit && options?.cash_limit != null"
                            class="rounded-lg bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-400"
                            data-test="exchange-over-limit"
                        >
                            You may refund up to
                            {{ money(options.cash_limit) }} in cash. Exchange it
                            for new items, or ask the owner.
                        </p>

                        <div class="grid min-w-0 gap-2 sm:grid-cols-2">
                            <Button
                                variant="outline"
                                class="h-12 text-base"
                                :class="{
                                    'border-destructive text-destructive':
                                        confirmReturn,
                                }"
                                :disabled="!ready"
                                data-test="exchange-return-only"
                                @click="returnOnly"
                            >
                                {{
                                    saving
                                        ? 'Recording...'
                                        : confirmReturn
                                          ? `Confirm: refund ${money(credit ?? 0)} cash`
                                          : `Return only · refund ${money(credit ?? 0)}`
                                }}
                            </Button>
                            <Button
                                class="h-12 text-base font-bold"
                                :disabled="!ready"
                                data-test="exchange-continue"
                                @click="exchange"
                            >
                                Exchange · add new items
                            </Button>
                        </div>
                    </template>
                </div>
            </template>
        </DialogContent>
    </Dialog>
</template>
