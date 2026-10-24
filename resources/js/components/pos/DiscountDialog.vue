<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { usePosCart } from '@/composables/usePosCart';
import { formatMoney } from '@/lib/money';
import type { PosShop } from '@/types/pos';

const props = defineProps<{ open: boolean; shop: PosShop }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const { state } = usePosCart();
const input = ref<HTMLInputElement | null>(null);

watch(
    () => props.open,
    async (open) => {
        if (open) {
            await nextTick();
            input.value?.focus();
            input.value?.select();
        }
    },
);
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Discount on the whole sale</DialogTitle>
                <DialogDescription>
                    Enter an amount in {{ shop.currency }}. Your limit is
                    checked by the server. Per-line discounts are on each cart
                    line.
                </DialogDescription>
            </DialogHeader>

            <input
                ref="input"
                v-model="state.saleDiscount"
                type="text"
                inputmode="decimal"
                placeholder="0.00"
                aria-label="Sale discount"
                class="h-16 w-full rounded-xl border bg-background px-4 text-right text-3xl font-bold tabular-nums"
                data-test="sale-discount"
                @keydown.enter.prevent="emit('update:open', false)"
            />

            <p v-if="state.quote" class="text-sm text-muted-foreground">
                Discounts so far:
                {{
                    formatMoney(
                        state.quote.totals.discount_total,
                        shop.decimals,
                        shop.currency,
                    )
                }}
                · New total:
                {{
                    formatMoney(
                        state.quote.totals.grand_total,
                        shop.decimals,
                        shop.currency,
                    )
                }}
            </p>
            <p
                v-if="state.quoteError"
                class="rounded-lg bg-destructive/10 p-3 text-sm font-medium text-destructive"
                role="alert"
            >
                {{ state.quoteError }}
            </p>

            <DialogFooter class="gap-2">
                <Button
                    variant="outline"
                    class="h-12"
                    @click="state.saleDiscount = ''"
                    >Remove discount</Button
                >
                <Button class="h-12" @click="emit('update:open', false)"
                    >Done</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
