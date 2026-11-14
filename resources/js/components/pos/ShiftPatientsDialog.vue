<script setup lang="ts">
import { Printer, WifiOff } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ShiftController from '@/actions/App/Http/Controllers/Pos/ShiftController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useOfflinePos } from '@/composables/useOfflinePos';
import { ApiError, getJson, queryString } from '@/lib/http';
import { offlineShiftBills } from '@/lib/offline/shiftPatients';
import { reprintSale } from '@/lib/receipt/reprint';
import type { PosShift, PosTill, ShiftPatientBill } from '@/types/pos';

/**
 * Patients this shift (Alt+P, pharmacy; docs/PHARMACY.md, branch 16): the
 * bills with a patient in this till's open shift, oldest first, each with
 * Reprint. Read only, and only this till's open shift. Offline it shows
 * the bills this till made offline in the shift (from its outbox); bills
 * rung up online need a connection, and so does a reprint (it is logged).
 */
const props = defineProps<{ open: boolean; till: PosTill; shift: PosShift }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();

type Row = {
    key: string;
    uuid: string;
    time: string;
    patient: string;
    age: string | null;
    invoiceNo: string;
    badge: string | null;
    canReprint: boolean;
};

const offlinePos = useOfflinePos();
const rows = ref<Row[] | null>(null);
const withoutPatient = ref(0);
const shownOffline = ref(false);
const error = ref<string | null>(null);
const reprinting = ref<string | null>(null);
let controller: AbortController | undefined;

/** "Y-m-d H:i" → "H:i", with the day in front when it is not the shift's first day. */
function time(local: string, firstDay: string): string {
    const [day, clock] = local.split(' ');

    return day === firstDay
        ? (clock ?? local)
        : `${day.slice(8, 10)}/${day.slice(5, 7)} ${clock ?? ''}`.trim();
}

const statusLabel = (status: string) =>
    status.replace(/_/g, ' ').replace(/^\w/, (letter) => letter.toUpperCase());

async function loadOnline(): Promise<void> {
    controller?.abort();
    controller = new AbortController();

    const response = await getJson<{
        shift: { uuid: string; opened_at: string } | null;
        bills: ShiftPatientBill[];
        without_patient: number;
    }>(
        ShiftController.patients.url() +
            queryString({ device: props.till.uuid }),
        controller.signal,
    );
    const firstDay = (response.shift?.opened_at ?? '').slice(0, 10);

    rows.value = response.bills.map((bill) => ({
        key: bill.uuid,
        uuid: bill.uuid,
        time: time(bill.sold_at, firstDay),
        patient: bill.patient,
        age: bill.age,
        invoiceNo: bill.invoice_no,
        badge:
            bill.status === 'completed'
                ? bill.bill_type === 'opd'
                    ? 'OPD'
                    : null
                : statusLabel(bill.status),
        canReprint: true,
    }));
    withoutPatient.value = response.without_patient;
    shownOffline.value = false;
}

async function loadOffline(): Promise<void> {
    const opened = new Date(props.shift.opened_at);
    const firstDay = opened
        .toLocaleString('sv-SE', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        })
        .slice(0, 10);
    const bills = await offlineShiftBills(
        props.till.uuid,
        props.shift.opened_at,
    );

    rows.value = bills.map((bill) => ({
        key: bill.uuid,
        uuid: bill.uuid,
        time: time(bill.soldAt, firstDay),
        patient: bill.patient,
        age: bill.age,
        invoiceNo: bill.invoiceNo,
        badge: bill.unsynced ? 'Not synced yet' : null,
        canReprint: false,
    }));
    withoutPatient.value = 0;
    shownOffline.value = true;
}

async function load(): Promise<void> {
    rows.value = null;
    error.value = null;

    try {
        if (offlinePos.isOffline.value) {
            await loadOffline();
        } else {
            await loadOnline();
        }
    } catch (caught) {
        if (caught instanceof DOMException && caught.name === 'AbortError') {
            return;
        }

        if (caught instanceof ApiError) {
            error.value = caught.message;

            return;
        }

        // The connection dropped on the way: show what this till holds.
        try {
            await loadOffline();
        } catch {
            error.value = 'Could not load the patients of this shift.';
        }
    }
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            void load();
        } else {
            controller?.abort();
        }
    },
);

watch(
    () => offlinePos.isOffline.value,
    () => {
        if (props.open) {
            void load();
        }
    },
);

async function reprint(row: Row): Promise<void> {
    if (reprinting.value !== null || !row.canReprint) {
        return;
    }

    reprinting.value = row.uuid;
    error.value = null;

    try {
        await reprintSale(row.uuid, props.till.uuid);
    } catch (caught) {
        error.value =
            caught instanceof ApiError
                ? caught.message
                : 'The receipt could not be reprinted.';
    } finally {
        reprinting.value = null;
    }
}

const emptyText = computed(() =>
    shownOffline.value
        ? 'No bills with a patient made offline on this till in this shift.'
        : 'No bills with a patient in this shift yet.',
);
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="sm:max-w-2xl" data-test="shift-patients">
            <DialogHeader>
                <DialogTitle class="text-xl">Patients this shift</DialogTitle>
                <DialogDescription
                    >Bills with a patient on {{ till.code }} since this shift
                    opened. Reprints are printed as duplicates and
                    logged.</DialogDescription
                >
            </DialogHeader>

            <p
                v-if="shownOffline"
                class="flex items-start gap-2 rounded-lg bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-400"
                data-test="shift-patients-offline"
            >
                <WifiOff class="mt-0.5 size-4 shrink-0" />
                <span
                    >Offline: these are the bills this till made offline in this
                    shift. Bills rung up online in this shift, and reprints,
                    need a connection.</span
                >
            </p>

            <p
                v-if="error"
                class="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive"
                role="alert"
            >
                {{ error }}
            </p>

            <div class="max-h-[60dvh] min-h-40 overflow-y-auto">
                <p
                    v-if="rows === null && !error"
                    class="py-8 text-center text-muted-foreground"
                >
                    Loading...
                </p>
                <p
                    v-else-if="rows && rows.length === 0"
                    class="py-8 text-center text-muted-foreground"
                    data-test="shift-patients-empty"
                >
                    {{ emptyText }}
                </p>
                <ul v-else-if="rows" class="divide-y">
                    <li
                        v-for="row in rows"
                        :key="row.key"
                        class="flex items-center gap-3 py-2"
                        data-test="shift-patient"
                    >
                        <span
                            class="w-16 shrink-0 text-sm text-muted-foreground tabular-nums"
                            >{{ row.time }}</span
                        >
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-bold">
                                {{ row.patient }}
                                <span
                                    v-if="row.age"
                                    class="font-normal text-muted-foreground"
                                    >· {{ row.age }}</span
                                >
                            </p>
                            <p
                                class="flex items-center gap-2 text-sm text-muted-foreground"
                            >
                                <span class="whitespace-nowrap">{{
                                    row.invoiceNo
                                }}</span>
                                <span
                                    v-if="row.badge"
                                    class="rounded bg-amber-500/15 px-1.5 py-0.5 text-xs font-semibold text-amber-700 dark:text-amber-400"
                                    >{{ row.badge }}</span
                                >
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            class="h-11 shrink-0"
                            :disabled="reprinting !== null || !row.canReprint"
                            :title="
                                row.canReprint
                                    ? undefined
                                    : 'Reprint needs a connection'
                            "
                            data-test="shift-patient-reprint"
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

            <p
                v-if="!shownOffline && withoutPatient > 0"
                class="text-sm text-muted-foreground"
                data-test="shift-patients-without"
            >
                {{ withoutPatient }}
                {{ withoutPatient === 1 ? 'bill' : 'bills' }} in this shift with
                no patient recorded.
            </p>
        </DialogContent>
    </Dialog>
</template>
