<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import ShiftController from '@/actions/App/Http/Controllers/Pos/ShiftController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { ApiError, getJson, postJson, queryString } from '@/lib/http';
import { formatMoney } from '@/lib/money';
import type { PosShop, PosTill } from '@/types/pos';

const props = defineProps<{ open: boolean; till: PosTill; shop: PosShop }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();

type Count = {
    expected_cash: number;
    counted_cash: number | null;
    difference: number | null;
};

/** The shift's takings: what a cashier sees of their own shift (ShiftService::summary). */
type Summary = {
    bills: number;
    gross: number;
    refunds: number;
    refunds_count: number;
    voids: number;
    voids_total: number;
    net: number;
    payments: { method: string; label: string; amount: number }[];
    cash: {
        opening: number;
        sales: number;
        refunds: number;
        expected: number;
        counted: number | null;
        difference: number | null;
    };
};

const counted = ref('');
const count = ref<Count | null>(null);
const closed = ref<(Count & { uuid: string }) | null>(null);
const summary = ref<Summary | null>(null);
const error = ref<string | null>(null);
const busy = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

async function load(): Promise<void> {
    try {
        const response = await getJson<Count & { summary: Summary | null }>(
            ShiftController.expected.url() +
                queryString({
                    device: props.till.uuid,
                    counted_cash: counted.value,
                }),
        );
        count.value = response;
        summary.value = response.summary;
        error.value = null;
    } catch (caught) {
        error.value =
            caught instanceof ApiError
                ? caught.message
                : 'Could not load the shift.';
    }
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            counted.value = '';
            count.value = null;
            closed.value = null;
            summary.value = null;
            error.value = null;
            void load();
        }
    },
);

// The server works out the difference; this only asks it, a moment after typing stops.
watch(counted, () => {
    clearTimeout(timer);
    timer = setTimeout(() => void load(), 250);
});

async function close(): Promise<void> {
    busy.value = true;
    error.value = null;

    try {
        const response = await postJson<{
            closed: Count & { uuid: string };
            summary: Summary | null;
        }>(ShiftController.close.url(), {
            device: props.till.uuid,
            counted_cash: counted.value,
        });
        closed.value = response.closed;
        summary.value = response.summary;
    } catch (caught) {
        error.value =
            caught instanceof ApiError
                ? caught.message
                : 'Could not close the shift.';
    } finally {
        busy.value = false;
    }
}

function done(): void {
    emit('update:open', false);
    router.reload({ only: ['shift', 'tills'] });
}

function money(minor: number | null): string {
    return formatMoney(minor, props.shop.decimals, props.shop.currency);
}
</script>

<template>
    <Dialog
        :open="open"
        @update:open="closed ? done() : emit('update:open', $event)"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    closed ? 'Shift closed' : 'Close shift'
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        closed
                            ? 'Hand the drawer in with this summary.'
                            : 'Count the cash in the drawer and enter the total.'
                    }}
                </DialogDescription>
            </DialogHeader>

            <dl
                v-if="summary"
                class="grid grid-cols-[1fr_auto] gap-y-1 rounded-xl border p-3 text-sm"
                data-test="shift-summary"
            >
                <dt class="text-muted-foreground">Bills</dt>
                <dd class="text-right tabular-nums">{{ summary.bills }}</dd>
                <dt class="text-muted-foreground">Sales</dt>
                <dd class="text-right tabular-nums">
                    {{ money(summary.gross) }}
                </dd>
                <dt class="text-muted-foreground">
                    Refunds ({{ summary.refunds_count }})
                </dt>
                <dd class="text-right tabular-nums">
                    {{ money(-summary.refunds) }}
                </dd>
                <dt class="font-medium">Net sales</dt>
                <dd class="text-right font-semibold tabular-nums">
                    {{ money(summary.net) }}
                </dd>
                <dt class="text-muted-foreground">
                    Voided bills ({{ summary.voids }})
                </dt>
                <dd class="text-right text-muted-foreground tabular-nums">
                    {{ money(summary.voids_total) }}
                </dd>
                <template
                    v-for="payment in summary.payments"
                    :key="payment.method"
                >
                    <dt class="text-muted-foreground">{{ payment.label }}</dt>
                    <dd class="text-right tabular-nums">
                        {{ money(payment.amount) }}
                    </dd>
                </template>
                <dt class="text-muted-foreground">Float + cash − refunds</dt>
                <dd class="text-right tabular-nums">
                    {{ money(summary.cash.opening) }} +
                    {{ money(summary.cash.sales) }} −
                    {{ money(summary.cash.refunds) }}
                </dd>
            </dl>

            <dl class="grid grid-cols-[1fr_auto] gap-y-2 text-lg">
                <dt class="text-muted-foreground">Expected cash</dt>
                <dd
                    class="text-right font-semibold tabular-nums"
                    data-test="expected-cash"
                >
                    {{ money((closed ?? count)?.expected_cash ?? null) }}
                </dd>
                <template v-if="closed">
                    <dt class="text-muted-foreground">Counted cash</dt>
                    <dd class="text-right font-semibold tabular-nums">
                        {{ money(closed.counted_cash) }}
                    </dd>
                </template>
            </dl>

            <label v-if="!closed" class="grid gap-2">
                <span class="font-medium"
                    >Counted cash ({{ shop.currency }})</span
                >
                <input
                    v-model="counted"
                    type="text"
                    inputmode="decimal"
                    placeholder="0.00"
                    autofocus
                    class="h-16 w-full min-w-0 rounded-xl border bg-background px-4 text-right text-3xl font-bold tabular-nums"
                    data-test="counted-cash"
                    @keydown.enter.prevent="counted !== '' && close()"
                />
            </label>

            <div
                v-if="(closed ?? count)?.difference != null"
                class="rounded-xl border p-4 text-center"
                data-test="shift-difference"
            >
                <p class="text-sm text-muted-foreground">Difference</p>
                <p
                    class="text-4xl font-extrabold tabular-nums"
                    :class="
                        (closed ?? count)?.difference === 0
                            ? 'text-emerald-600'
                            : 'text-destructive'
                    "
                >
                    {{ money((closed ?? count)?.difference ?? null) }}
                </p>
            </div>

            <p
                v-if="error"
                class="rounded-lg bg-destructive/10 p-3 text-sm font-medium text-destructive"
                role="alert"
            >
                {{ error }}
            </p>

            <a
                v-if="closed && summary"
                :href="ShiftController.summary.url(closed.uuid) + '?print=1'"
                target="_blank"
                rel="noopener"
                class="text-center text-sm font-medium underline underline-offset-4"
                data-test="print-shift-summary"
                >Print the shift summary</a
            >
            <Button v-if="closed" class="h-14 text-lg" @click="done"
                >Done</Button
            >
            <Button
                v-else
                class="h-14 text-lg"
                :disabled="busy || counted === ''"
                data-test="close-shift"
                @click="close"
            >
                Close shift
            </Button>
        </DialogContent>
    </Dialog>
</template>
