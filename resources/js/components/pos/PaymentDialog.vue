<script setup lang="ts">
import { Banknote, CreditCard, Landmark, Trash2 } from '@lucide/vue';
import { computed, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { usePosCart } from '@/composables/usePosCart';
import { formatMoney, toInputValue } from '@/lib/money';
import type { PaymentMethod, PaymentRow, PosShop } from '@/types/pos';

const props = defineProps<{
    open: boolean;
    shop: PosShop;
    submitting: boolean;
    error: string | null;
    /** Shop setting bank_transfer_reference_required: a bank transfer needs its reference. */
    referenceRequired?: boolean;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean]; complete: [] }>();

const { state, addPayment, removePayment } = usePosCart();

const methods: {
    value: PaymentMethod;
    label: string;
    icon: typeof Banknote;
}[] = [
    { value: 'cash', label: 'Cash', icon: Banknote },
    { value: 'card', label: 'Card', icon: CreditCard },
    { value: 'bank_transfer', label: 'Bank transfer', icon: Landmark },
];

const total = computed(() => state.quote?.totals.grand_total ?? 0);
const preview = computed(() => state.quote?.payment ?? null);
const outstanding = computed(() => preview.value?.short_by ?? total.value);
const canComplete = computed(
    () =>
        !state.stale &&
        !props.submitting &&
        preview.value !== null &&
        preview.value.error === null &&
        !referenceMissing.value,
);

watch(
    () => props.open,
    (open) => {
        if (open && state.payments.length === 0) {
            addPayment('cash');
        }
    },
);

function money(minor: number): string {
    return formatMoney(minor, props.shop.decimals, props.shop.currency);
}

function methodLabel(method: PaymentMethod): string {
    return methods.find((item) => item.value === method)?.label ?? method;
}

function addMethod(method: PaymentMethod): void {
    addPayment(
        method,
        outstanding.value > 0
            ? toInputValue(outstanding.value, props.shop.decimals)
            : '',
    );
}

/** A bank transfer still without the reference the shop requires. */
function missingReference(row: PaymentRow): boolean {
    return (
        !!props.referenceRequired &&
        row.method === 'bank_transfer' &&
        row.reference.trim() === ''
    );
}

const referenceMissing = computed(() =>
    state.payments.some((row) => missingReference(row)),
);

function setCash(amount: string): void {
    const cash = state.payments.find((row) => row.method === 'cash');

    if (cash) {
        cash.amount = amount;
    } else {
        addPayment('cash', amount);
    }
}

function exact(): void {
    const cashElsewhere = state.payments
        .filter((row) => row.method !== 'cash')
        .some((row) => row.amount !== '');

    // With other payments entered, "exact" means whatever is still outstanding.
    setCash(
        toInputValue(
            cashElsewhere ? outstanding.value : total.value,
            props.shop.decimals,
        ),
    );
}

function onKey(event: KeyboardEvent): void {
    if (
        event.key === 'Enter' &&
        (event.ctrlKey || event.metaKey) &&
        canComplete.value
    ) {
        event.preventDefault();
        emit('complete');
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            class="max-h-[95vh] gap-4 overflow-y-auto sm:max-w-2xl"
            @keydown="onKey"
        >
            <DialogHeader>
                <DialogTitle>Payment</DialogTitle>
                <DialogDescription
                    >Cash, card, bank transfer or a split across
                    them.</DialogDescription
                >
            </DialogHeader>

            <div
                class="flex items-baseline justify-between rounded-xl bg-muted p-4"
            >
                <span class="text-lg text-muted-foreground">Total due</span>
                <span
                    class="text-4xl font-extrabold tabular-nums"
                    data-test="payment-total"
                    >{{ money(total) }}</span
                >
            </div>

            <div class="flex flex-wrap gap-2" data-test="quick-cash">
                <Button
                    variant="secondary"
                    class="h-14 min-w-24 text-base"
                    @click="exact"
                    >Exact</Button
                >
                <Button
                    v-for="note in shop.quickCash"
                    :key="note"
                    variant="outline"
                    class="h-14 min-w-20 text-base tabular-nums"
                    @click="setCash(String(note))"
                >
                    {{ note.toLocaleString('en-US') }}
                </Button>
            </div>

            <ul class="grid gap-2">
                <li
                    v-for="row in state.payments"
                    :key="row.key"
                    class="grid grid-cols-[7rem_1fr_auto] items-center gap-2"
                >
                    <span class="font-medium">{{
                        methodLabel(row.method)
                    }}</span>
                    <div class="flex flex-wrap gap-2 sm:flex-nowrap">
                        <input
                            v-model="row.amount"
                            type="text"
                            inputmode="decimal"
                            placeholder="0.00"
                            :aria-label="`${methodLabel(row.method)} amount`"
                            class="h-14 w-full min-w-0 rounded-lg border bg-background px-3 text-right text-xl font-semibold tabular-nums"
                            data-test="payment-amount"
                        />
                        <input
                            v-if="row.method !== 'cash'"
                            v-model="row.reference"
                            type="text"
                            maxlength="100"
                            :placeholder="
                                referenceRequired &&
                                row.method === 'bank_transfer'
                                    ? 'Reference (required)'
                                    : 'Reference'
                            "
                            class="h-14 w-full rounded-lg border bg-background px-3 text-base sm:w-44"
                            :class="{
                                'border-destructive': missingReference(row),
                            }"
                            :aria-invalid="missingReference(row)"
                            aria-label="Reference"
                            data-test="payment-reference"
                        />
                    </div>
                    <Button
                        variant="ghost"
                        size="icon-lg"
                        class="size-12 text-destructive"
                        aria-label="Remove payment"
                        @click="removePayment(row)"
                    >
                        <Trash2 class="size-5" />
                    </Button>
                </li>
            </ul>

            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="method in methods"
                    :key="method.value"
                    variant="outline"
                    class="h-12"
                    @click="addMethod(method.value)"
                >
                    <component :is="method.icon" class="size-4" /> Add
                    {{ method.label.toLowerCase() }}
                </Button>
            </div>

            <div
                class="grid grid-cols-2 gap-4 rounded-xl border p-4 text-center"
            >
                <div>
                    <p class="text-sm text-muted-foreground">Paid</p>
                    <p class="text-2xl font-bold tabular-nums">
                        {{ money(preview?.paid_total ?? 0) }}
                    </p>
                </div>
                <div v-if="preview && preview.short_by > 0">
                    <p class="text-sm text-muted-foreground">Still to pay</p>
                    <p
                        class="text-2xl font-bold text-destructive tabular-nums"
                        data-test="short-by"
                    >
                        {{ money(preview.short_by) }}
                    </p>
                </div>
                <div v-else>
                    <p class="text-sm text-muted-foreground">Change due</p>
                    <p
                        class="text-5xl font-extrabold text-emerald-600 tabular-nums dark:text-emerald-400"
                        data-test="change-due"
                    >
                        {{ money(preview?.change_due ?? 0) }}
                    </p>
                </div>
            </div>

            <p
                v-if="preview?.error && preview.short_by === 0"
                class="rounded-lg bg-destructive/10 p-3 text-sm font-medium text-destructive"
                role="alert"
            >
                {{ preview.error }}
            </p>
            <p
                v-if="referenceMissing && !preview?.error"
                class="rounded-lg bg-destructive/10 p-3 text-sm font-medium text-destructive"
                role="alert"
                data-test="reference-required"
            >
                Enter the bank transfer reference.
            </p>
            <p
                v-if="error"
                class="rounded-lg bg-destructive/10 p-3 text-sm font-medium text-destructive"
                role="alert"
                data-test="checkout-error"
            >
                {{ error }}
            </p>

            <Button
                class="h-16 text-xl font-bold"
                :disabled="!canComplete"
                data-test="complete-sale"
                @click="emit('complete')"
            >
                {{ submitting ? 'Recording sale…' : 'Complete sale' }}
            </Button>
        </DialogContent>
    </Dialog>
</template>
