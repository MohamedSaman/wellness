<script setup lang="ts">
import { BadgePercent, CreditCard, TriangleAlert, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { usePosCart } from '@/composables/usePosCart';
import { formatMoney } from '@/lib/money';
import type { Quote } from '@/types/pos';

const props = defineProps<{
    quote: Quote | null;
    error: string | null;
    stale: boolean;
    hasLines: boolean;
    decimals: number;
    currency: string;
    /** OPD bill: Consultation + one typed Medicines amount, no discounts. */
    opdBill?: boolean;
    /** OPD bill: the shop warns below this % of the medicines' list value (0 = never). */
    belowListPercent?: number;
    /** The cashier may give a discount (a limit above 0, or no limit); else no Discount button. */
    canDiscount?: boolean;
}>();

const emit = defineEmits<{
    pay: [];
    discount: [];
    clear: [];
    /** Enter in the Medicines amount: back to the scanner. */
    medicinesDone: [];
}>();

const { state } = usePosCart();
const medicinesInput = ref<HTMLInputElement | null>(null);

/**
 * Tax added on top of the lines (exclusive): the total is the subtotal less
 * discounts plus tax, and each total already carries its tax. Inclusive tax
 * is inside the amounts, as on the Subtotal row.
 */
const taxOnTop = computed(() => {
    const totals = props.quote?.totals;

    return (
        !!totals &&
        totals.tax_total > 0 &&
        totals.grand_total ===
            totals.subtotal - totals.discount_total + totals.tax_total
    );
});

/**
 * OPD bill: the Medicines amount before exclusive tax, from the quote's
 * totals (online and offline alike): a medicine line's own share is never
 * on the quote. Consultation + Medicines + Tax = Total.
 */
const medicinesTotal = computed(() => {
    const totals = props.quote?.totals;

    if (!totals) {
        return 0;
    }

    return (
        (totals.medicines_total ?? 0) -
        (taxOnTop.value ? (totals.medicines_tax ?? 0) : 0)
    );
});

/** OPD bill: the consultation and any other service lines (everything but medicines). */
const servicesTotal = computed(() => {
    const totals = props.quote?.totals;

    if (!totals) {
        return 0;
    }

    const services = totals.grand_total - (totals.medicines_total ?? 0);
    const servicesTax = totals.tax_total - (totals.medicines_tax ?? 0);

    return services - (taxOnTop.value ? servicesTax : 0);
});

/**
 * OPD bill: the priced medicines at list price (from the quote, online and
 * offline alike; never a cost), shown next to the Medicines amount, and the
 * soft warning when the amount typed is below the shop's share of it. It
 * never stops the sale.
 */
const listValue = computed(() =>
    props.opdBill ? (props.quote?.totals.medicines_list_value ?? null) : null,
);
const belowList = computed(
    () => !!props.opdBill && !!props.quote?.totals.medicines_below_list,
);
const belowListMessage = computed(() =>
    (props.belowListPercent ?? 100) >= 100
        ? "Below the medicines' list value. Check the amount; you can still take payment."
        : `Below ${props.belowListPercent}% of the medicines' list value. Check the amount; you can still take payment.`,
);

function focusMedicines(): void {
    medicinesInput.value?.focus();
    medicinesInput.value?.select();
}

defineExpose({ focusMedicines });
</script>

<template>
    <div class="space-y-3 border-t pt-3">
        <label
            v-if="opdBill"
            class="flex items-center justify-between gap-3"
            data-test="medicines-amount-row"
        >
            <span class="text-base font-semibold"
                >Medicines amount
                <kbd class="text-xs font-normal text-muted-foreground"
                    >F8</kbd
                ></span
            >
            <input
                ref="medicinesInput"
                v-model="state.medicinesAmount"
                type="text"
                inputmode="decimal"
                placeholder="0.00"
                aria-label="Medicines amount"
                class="h-14 w-44 rounded-xl border bg-background px-3 text-right text-xl font-bold tabular-nums"
                data-test="medicines-amount"
                @keydown.enter.prevent="emit('medicinesDone')"
            />
        </label>
        <p
            v-if="listValue !== null && listValue > 0"
            class="flex items-center justify-between gap-3 text-sm text-muted-foreground"
            data-test="medicines-list-value"
        >
            <span>List value of the medicines</span>
            <span class="tabular-nums">{{
                formatMoney(listValue, decimals, currency)
            }}</span>
        </p>
        <p
            v-if="belowList"
            class="flex items-start gap-2 rounded-lg bg-amber-500/10 p-3 text-sm font-medium text-amber-700 dark:text-amber-400"
            role="status"
            data-test="medicines-below-list"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" />
            <span class="min-w-0">{{ belowListMessage }}</span>
        </p>

        <dl
            v-if="quote"
            class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-base"
            :class="{ 'opacity-60': stale }"
        >
            <template v-if="opdBill">
                <dt class="text-muted-foreground">Consultation</dt>
                <dd class="text-right tabular-nums" data-test="opd-services">
                    {{ formatMoney(servicesTotal, decimals, currency) }}
                </dd>
                <dt class="text-muted-foreground">Medicines</dt>
                <dd class="text-right tabular-nums" data-test="opd-medicines">
                    {{ formatMoney(medicinesTotal, decimals, currency) }}
                </dd>
            </template>
            <template v-else>
                <dt class="text-muted-foreground">Subtotal</dt>
                <dd class="text-right tabular-nums">
                    {{ formatMoney(quote.totals.subtotal, decimals, currency) }}
                </dd>
                <template v-if="quote.totals.discount_total > 0">
                    <dt class="text-muted-foreground">Discounts</dt>
                    <dd class="text-right tabular-nums">
                        -{{
                            formatMoney(
                                quote.totals.discount_total,
                                decimals,
                                currency,
                            )
                        }}
                    </dd>
                </template>
            </template>
            <template v-if="quote.totals.tax_total > 0">
                <dt class="text-muted-foreground">Tax</dt>
                <dd class="text-right tabular-nums">
                    {{
                        formatMoney(quote.totals.tax_total, decimals, currency)
                    }}
                </dd>
            </template>
            <dt class="text-2xl font-bold">Total</dt>
            <dd
                class="text-right text-3xl font-extrabold tabular-nums"
                data-test="grand-total"
            >
                {{ formatMoney(quote.totals.grand_total, decimals, currency) }}
            </dd>
        </dl>

        <p
            v-if="error"
            class="rounded-lg bg-destructive/10 p-3 text-sm font-medium text-destructive"
            role="alert"
            data-test="quote-error"
        >
            {{ error }}
        </p>

        <div
            class="grid gap-2"
            :class="
                opdBill || canDiscount === false
                    ? 'grid-cols-[auto_1fr]'
                    : 'grid-cols-[auto_1fr_auto]'
            "
        >
            <Button
                variant="outline"
                class="h-14 px-4"
                :disabled="!hasLines"
                aria-label="Clear cart"
                @click="$emit('clear')"
            >
                <Trash2 class="size-5" />
            </Button>
            <Button
                v-if="!opdBill && canDiscount !== false"
                variant="outline"
                class="h-14 text-base"
                :disabled="!hasLines"
                data-test="discount-button"
                @click="$emit('discount')"
            >
                <BadgePercent class="size-5" /> Discount
                <kbd class="text-xs text-muted-foreground">F4</kbd>
            </Button>
            <Button
                class="h-14 px-6 text-lg font-bold"
                :disabled="!hasLines || !quote || stale || !!error"
                data-test="pay-button"
                @click="$emit('pay')"
            >
                <CreditCard class="size-5" /> Pay
                <kbd class="text-xs opacity-80">F9</kbd>
            </Button>
        </div>
    </div>
</template>
