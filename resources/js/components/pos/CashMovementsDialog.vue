<script setup lang="ts">
import { Ban, Printer, Undo2, WifiOff } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import CashMovementController from '@/actions/App/Http/Controllers/Pos/CashMovementController';
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
import { printCashMovement, reprintCashMovement } from '@/lib/receipt/reprint';
import type {
    CashMovementRow,
    CashMovementTypeOption,
    DoctorReference,
    PosShop,
    PosTill,
} from '@/types/pos';

/**
 * Cash (cash_movements; docs/PHARMACY.md, branch 18): pay cash out of the
 * drawer, take cash in, and the movements of this till's open shift with
 * their slips. Each movement prints a slip at once; a reprint is logged and
 * marked REPRINT. The owner may void one while the shift is open. Online
 * only: the server keeps the drawer's figures.
 */
const props = defineProps<{
    open: boolean;
    till: PosTill;
    shop: PosShop;
    /** The tab to start on ('paid_out' when omitted). */
    startWith?: string | null;
    /** returns.no_receipt in a pharmacy: offers Return without receipt. */
    canReturnWithoutReceipt?: boolean;
}>();
const emit = defineEmits<{
    'update:open': [value: boolean];
    recorded: [];
    'no-receipt': [];
}>();

const offlinePos = useOfflinePos();
const types = ref<CashMovementTypeOption[]>([]);
const movements = ref<CashMovementRow[] | null>(null);
const canVoid = ref(false);
const hasShift = ref(true);
const error = ref<string | null>(null);
const notice = ref<string | null>(null);
const saving = ref(false);
const busy = ref<string | null>(null);

const type = ref<string>('paid_out');
const amount = ref('');
/** Pay doctor (pharmacy): the active doctors, and for reference only, today's OPD figures and payments. */
const doctors = ref<{ id: number; name: string }[]>([]);
const reference = ref<DoctorReference | null>(null);
const doctorId = ref<number | null>(null);
const category = ref<string | null>(null);
const note = ref('');
const amountInput = ref<HTMLInputElement | null>(null);

const voiding = ref<string | null>(null);
const voidReason = ref('');

let controller: AbortController | undefined;

const current = computed(
    () => types.value.find((option) => option.value === type.value) ?? null,
);
const money = (minor: number) =>
    formatMoney(minor, props.shop.decimals, props.shop.currency);
const shownTime = (local: string) => local.slice(11);
const submitLabel = computed(() =>
    type.value === 'paid_in'
        ? 'Take in & print'
        : type.value === 'doctor_payment'
          ? 'Pay doctor & print'
          : 'Pay out & print',
);
const paidTodayToDoctor = computed(() =>
    (reference.value?.paid_today ?? []).filter(
        (payment) => payment.doctor_id === doctorId.value,
    ),
);

async function load(): Promise<void> {
    controller?.abort();
    controller = new AbortController();

    try {
        const response = await getJson<{
            shift: { uuid: string } | null;
            movements: CashMovementRow[];
            types: CashMovementTypeOption[];
            can_void: boolean;
            doctors: { id: number; name: string }[];
            reference: DoctorReference | null;
        }>(
            CashMovementController.index.url() +
                queryString({ device: props.till.uuid }),
            controller.signal,
        );
        types.value = response.types;
        movements.value = response.movements;
        canVoid.value = response.can_void;
        hasShift.value = response.shift !== null;
        doctors.value = response.doctors;
        reference.value = response.reference;

        if (
            doctorId.value !== null &&
            !response.doctors.some((doctor) => doctor.id === doctorId.value)
        ) {
            doctorId.value = null;
        }
        error.value = null;

        if (!response.types.some((option) => option.value === type.value)) {
            choose(response.types[0]?.value ?? 'paid_out');
        }
    } catch (caught) {
        if (caught instanceof DOMException && caught.name === 'AbortError') {
            return;
        }

        error.value =
            caught instanceof ApiError && caught.status !== 0
                ? caught.message
                : 'Could not load the cash movements. Cash in and out needs a connection.';
    }
}

function choose(value: string): void {
    type.value = value;
    category.value =
        types.value.find((option) => option.value === value)?.categories[0]
            ?.value ?? null;
    error.value = null;
    void nextTick(() => amountInput.value?.focus());
}

function reset(): void {
    amount.value = '';
    note.value = '';
    doctorId.value = null;
    voiding.value = null;
    voidReason.value = '';
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            movements.value = null;
            error.value = null;
            notice.value = null;
            reset();
            choose(props.startWith ?? 'paid_out');

            if (!offlinePos.isOffline.value) {
                void load();
            }
        } else {
            controller?.abort();
        }
    },
);

async function save(): Promise<void> {
    if (saving.value || current.value === null) {
        return;
    }

    if (type.value === 'doctor_payment' && doctorId.value === null) {
        error.value = 'Choose the doctor.';

        return;
    }

    if (amount.value.trim() === '') {
        error.value = 'Enter the amount.';

        return;
    }

    saving.value = true;
    error.value = null;
    notice.value = null;

    try {
        const movement = await printCashMovement({
            device: props.till.uuid,
            type: type.value,
            amount: amount.value.trim().replace(/,/g, ''),
            category: current.value.categories.length ? category.value : null,
            doctor: type.value === 'doctor_payment' ? doctorId.value : null,
            note: note.value.trim() || null,
        });
        notice.value = `${movement.number}: ${movement.label} ${money(movement.amount)} recorded. The slip is printing.`;
        reset();
        emit('recorded');
        await load();
    } catch (caught) {
        error.value =
            caught instanceof ApiError && caught.status !== 0
                ? caught.message
                : 'It was not recorded. Cash in and out needs a connection.';
    } finally {
        saving.value = false;
    }
}

async function reprint(row: CashMovementRow): Promise<void> {
    if (busy.value !== null) {
        return;
    }

    busy.value = row.uuid;
    error.value = null;

    try {
        await reprintCashMovement(row.uuid, props.till.uuid);
    } catch (caught) {
        error.value =
            caught instanceof ApiError && caught.status !== 0
                ? caught.message
                : 'The slip could not be reprinted. Reprinting needs a connection.';
    } finally {
        busy.value = null;
    }
}

async function confirmVoid(row: CashMovementRow): Promise<void> {
    if (busy.value !== null) {
        return;
    }

    busy.value = row.uuid;
    error.value = null;

    try {
        await postJson(
            CashMovementController.void.url({ cashMovement: row.uuid }),
            { reason: voidReason.value.trim() },
        );
        notice.value = `${row.number} voided.`;
        voiding.value = null;
        voidReason.value = '';
        emit('recorded');
        await load();
    } catch (caught) {
        error.value =
            caught instanceof ApiError ? caught.message : 'It was not voided.';
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent
            class="max-h-[95dvh] overflow-y-auto sm:max-w-2xl"
            data-test="cash-movements"
        >
            <DialogHeader>
                <DialogTitle class="text-xl">Cash in and out</DialogTitle>
                <DialogDescription
                    >Cash paid out of or into the {{ till.code }} drawer in this
                    shift. Each one prints a slip and changes the expected
                    cash.</DialogDescription
                >
            </DialogHeader>

            <Button
                v-if="canReturnWithoutReceipt && !offlinePos.isOffline.value"
                variant="outline"
                class="h-10 justify-self-start"
                data-test="open-no-receipt-return"
                @click="emit('no-receipt')"
            >
                <Undo2 class="size-4" /> Return without receipt
            </Button>

            <p
                v-if="offlinePos.isOffline.value"
                class="flex items-start gap-2 rounded-lg bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-400"
                data-test="cash-movements-offline"
            >
                <WifiOff class="mt-0.5 size-4 shrink-0" />
                <span
                    >Offline: cash in and out needs a connection. Try again when
                    this till is back online.</span
                >
            </p>

            <p
                v-if="error"
                class="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive"
                role="alert"
                data-test="cash-movement-error"
            >
                {{ error }}
            </p>
            <p
                v-if="notice"
                class="rounded-lg bg-emerald-500/10 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-400"
                role="status"
                data-test="cash-movement-notice"
            >
                {{ notice }}
            </p>

            <template v-if="!offlinePos.isOffline.value && movements !== null">
                <p
                    v-if="!hasShift"
                    class="rounded-lg bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-400"
                >
                    Open a shift on this till first: cash is paid in or out of a
                    shift's drawer.
                </p>

                <form
                    v-else-if="current"
                    class="grid min-w-0 gap-3"
                    data-test="cash-movement-form"
                    @submit.prevent="save"
                >
                    <div
                        class="flex flex-wrap gap-1"
                        role="tablist"
                        aria-label="Kind"
                    >
                        <Button
                            v-for="option in types"
                            :key="option.value"
                            type="button"
                            role="tab"
                            :aria-selected="option.value === type"
                            :variant="
                                option.value === type ? 'default' : 'outline'
                            "
                            class="h-11"
                            :data-test="`cash-type-${option.value}`"
                            @click="choose(option.value)"
                        >
                            {{ option.label }}
                        </Button>
                    </div>

                    <template v-if="type === 'doctor_payment'">
                        <label class="grid min-w-0 gap-1 text-sm font-medium">
                            Doctor
                            <select
                                v-model="doctorId"
                                class="h-12 min-w-0 rounded-lg border bg-background px-3 text-base"
                                data-test="cash-movement-doctor"
                            >
                                <option :value="null" disabled>
                                    Choose the doctor
                                </option>
                                <option
                                    v-for="doctor in doctors"
                                    :key="doctor.id"
                                    :value="doctor.id"
                                >
                                    {{ doctor.name }}
                                </option>
                            </select>
                            <span
                                v-if="doctors.length === 0"
                                class="text-xs font-normal text-muted-foreground"
                                >No active doctor: the owner adds them in Admin
                                → Doctors.</span
                            >
                        </label>
                        <div
                            v-if="reference"
                            class="grid min-w-0 gap-1 rounded-lg bg-muted/50 px-3 py-2 text-sm"
                            data-test="doctor-reference"
                        >
                            <p class="text-muted-foreground">
                                For reference only: today
                                {{ reference.opd_bills }} OPD
                                {{
                                    reference.opd_bills === 1
                                        ? 'bill'
                                        : 'bills'
                                }}, consultation fees
                                {{ money(reference.consultation_fees) }}.
                            </p>
                            <p
                                v-for="(payment, index) in paidTodayToDoctor"
                                :key="index"
                                class="font-medium text-amber-700 dark:text-amber-400"
                                data-test="doctor-paid-today"
                            >
                                Already paid today:
                                {{ money(payment.amount) }} at
                                {{ payment.at }} from {{ payment.till }} by
                                {{ payment.user }}.
                            </p>
                        </div>
                    </template>

                    <label class="grid min-w-0 gap-1 text-sm font-medium">
                        Amount ({{ shop.currency }})
                        <input
                            ref="amountInput"
                            v-model="amount"
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            class="h-12 min-w-0 rounded-lg border bg-background px-3 text-right text-xl font-bold tabular-nums"
                            data-test="cash-movement-amount"
                        />
                        <span
                            v-if="current.limit !== null"
                            class="text-xs font-normal text-muted-foreground"
                            >Up to {{ money(current.limit) }} at once.</span
                        >
                    </label>

                    <fieldset
                        v-if="current.categories.length"
                        class="grid min-w-0 gap-1 text-sm font-medium"
                    >
                        <legend class="mb-1">What for</legend>
                        <div class="flex flex-wrap gap-1">
                            <Button
                                v-for="option in current.categories"
                                :key="option.value"
                                type="button"
                                :variant="
                                    option.value === category
                                        ? 'secondary'
                                        : 'outline'
                                "
                                :aria-pressed="option.value === category"
                                class="h-10"
                                :data-test="`cash-category-${option.value}`"
                                @click="category = option.value"
                            >
                                {{ option.label }}
                            </Button>
                        </div>
                    </fieldset>

                    <label class="grid min-w-0 gap-1 text-sm font-medium">
                        Note{{
                            category === 'other' && type !== 'doctor_payment'
                                ? ''
                                : ' (optional)'
                        }}
                        <input
                            v-model="note"
                            type="text"
                            maxlength="500"
                            autocomplete="off"
                            class="h-11 min-w-0 rounded-lg border bg-background px-3 text-base"
                            data-test="cash-movement-note"
                        />
                    </label>

                    <Button
                        type="submit"
                        class="h-12 text-base"
                        :disabled="saving"
                        data-test="cash-movement-save"
                    >
                        <Printer class="size-4" />
                        {{ saving ? 'Recording...' : submitLabel }}
                    </Button>
                </form>
                <p v-else class="text-sm text-muted-foreground">
                    You may not pay cash in or out at this till.
                </p>

                <section aria-label="This shift" class="grid min-w-0 gap-2">
                    <h3 class="text-sm font-semibold">This shift</h3>
                    <p
                        v-if="movements.length === 0"
                        class="text-sm text-muted-foreground"
                        data-test="cash-movements-empty"
                    >
                        Nothing paid in or out yet.
                    </p>
                    <ul v-else class="divide-y rounded-lg border">
                        <li
                            v-for="row in movements"
                            :key="row.uuid"
                            class="grid min-w-0 gap-2 p-2"
                            data-test="cash-movement-row"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="truncate font-semibold"
                                        :class="{
                                            'text-muted-foreground line-through':
                                                row.voided,
                                        }"
                                    >
                                        {{ row.label }}
                                        <template v-if="row.doctor">
                                            · {{ row.doctor }}</template
                                        >
                                        <template v-else-if="row.category">
                                            · {{ row.category }}</template
                                        >
                                    </p>
                                    <p
                                        class="truncate text-sm text-muted-foreground"
                                    >
                                        {{ row.number }} ·
                                        {{ shownTime(row.at) }} · {{ row.user
                                        }}<template v-if="row.note">
                                            · {{ row.note }}</template
                                        >
                                        <template v-if="row.voided">
                                            · Voided: {{ row.void_reason }}
                                        </template>
                                    </p>
                                </div>
                                <p
                                    class="shrink-0 font-semibold tabular-nums"
                                    :class="{
                                        'text-muted-foreground line-through':
                                            row.voided,
                                    }"
                                >
                                    {{ row.type === 'paid_in' ? '+' : '−'
                                    }}{{ money(row.amount) }}
                                </p>
                                <Button
                                    variant="outline"
                                    class="h-10 shrink-0"
                                    :disabled="busy !== null"
                                    :title="`Reprint ${row.number}`"
                                    data-test="cash-movement-reprint"
                                    @click="reprint(row)"
                                >
                                    <Printer class="size-4" />
                                    <span class="sr-only sm:not-sr-only"
                                        >Reprint</span
                                    >
                                </Button>
                                <Button
                                    v-if="canVoid && !row.voided"
                                    variant="ghost"
                                    class="h-10 shrink-0"
                                    :title="`Void ${row.number}`"
                                    data-test="cash-movement-void"
                                    @click="
                                        voiding =
                                            voiding === row.uuid
                                                ? null
                                                : row.uuid
                                    "
                                >
                                    <Ban class="size-4" />
                                    <span class="sr-only sm:not-sr-only"
                                        >Void</span
                                    >
                                </Button>
                            </div>
                            <form
                                v-if="voiding === row.uuid"
                                class="flex min-w-0 gap-2"
                                @submit.prevent="confirmVoid(row)"
                            >
                                <input
                                    v-model="voidReason"
                                    type="text"
                                    maxlength="255"
                                    placeholder="Why is it voided?"
                                    aria-label="Reason for voiding"
                                    class="h-10 min-w-0 flex-1 rounded-lg border bg-background px-3 text-base"
                                    data-test="cash-movement-void-reason"
                                />
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    class="h-10 shrink-0"
                                    :disabled="busy !== null"
                                    data-test="cash-movement-void-confirm"
                                >
                                    Void
                                </Button>
                            </form>
                        </li>
                    </ul>
                </section>
            </template>
            <p
                v-else-if="!offlinePos.isOffline.value && !error"
                class="py-8 text-center text-muted-foreground"
            >
                Loading...
            </p>
        </DialogContent>
    </Dialog>
</template>
