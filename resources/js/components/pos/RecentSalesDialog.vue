<script setup lang="ts">
import { Printer, Search } from '@lucide/vue';
import { ref, watch } from 'vue';
import RecentSalesController from '@/actions/App/Http/Controllers/Pos/RecentSalesController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { ApiError, getJson, queryString } from '@/lib/http';
import { formatMoney } from '@/lib/money';
import { reprintExchange, reprintSale } from '@/lib/receipt/reprint';
import type { PosShop, PosTill, RecentSale } from '@/types/pos';

/** F6: this till's sales from the open shift and today, each with Reprint. */
const props = defineProps<{
    open: boolean;
    till: PosTill;
    shop: PosShop;
    /** Pharmacy: link to Reprint shift summary (branch 17). */
    shiftSummaries?: boolean;
}>();
const emit = defineEmits<{
    'update:open': [value: boolean];
    'shift-summaries': [];
}>();

const query = ref('');
const sales = ref<RecentSale[] | null>(null);
const error = ref<string | null>(null);
const reprinting = ref<string | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;

async function load(): Promise<void> {
    controller?.abort();
    controller = new AbortController();

    try {
        const response = await getJson<{ sales: RecentSale[] }>(
            RecentSalesController.url() +
                queryString({ device: props.till.uuid, q: query.value }),
            controller.signal,
        );
        sales.value = response.sales;
        error.value = null;
    } catch (caught) {
        if (caught instanceof DOMException && caught.name === 'AbortError') {
            return;
        }

        error.value =
            caught instanceof ApiError
                ? caught.message
                : 'Could not load the recent sales.';
    }
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            query.value = '';
            sales.value = null;
            error.value = null;
            void load();
        } else {
            controller?.abort();
        }
    },
);

watch(query, () => {
    clearTimeout(timer);
    timer = setTimeout(() => void load(), 250);
});

async function reprint(sale: RecentSale): Promise<void> {
    if (reprinting.value !== null) {
        return;
    }

    reprinting.value = sale.uuid;
    error.value = null;

    try {
        // An exchange's new bill (branch 19) reprints its EXCHANGE receipt.
        await (sale.exchange
            ? reprintExchange(sale.exchange.uuid, props.till.uuid)
            : reprintSale(sale.uuid, props.till.uuid));
    } catch (caught) {
        error.value =
            caught instanceof ApiError
                ? caught.message
                : 'The receipt could not be reprinted.';
    } finally {
        reprinting.value = null;
    }
}

const statusLabel = (status: string) =>
    status.replace(/_/g, ' ').replace(/^\w/, (letter) => letter.toUpperCase());
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="sm:max-w-2xl" data-test="recent-sales">
            <DialogHeader>
                <DialogTitle class="text-xl">Recent sales</DialogTitle>
                <DialogDescription
                    >Sales on {{ till.code }} from the current shift and today.
                    Reprints are printed as duplicates and
                    logged.</DialogDescription
                >
            </DialogHeader>

            <div class="relative">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="query"
                    class="h-12 pl-10 text-base"
                    placeholder="Invoice number"
                    aria-label="Search by invoice number"
                    data-test="recent-sales-search"
                    autofocus
                />
            </div>

            <p
                v-if="error"
                class="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive"
                role="alert"
            >
                {{ error }}
            </p>

            <div class="max-h-[60dvh] min-h-40 overflow-y-auto">
                <p
                    v-if="sales === null && !error"
                    class="py-8 text-center text-muted-foreground"
                >
                    Loading...
                </p>
                <p
                    v-else-if="sales && sales.length === 0"
                    class="py-8 text-center text-muted-foreground"
                >
                    {{
                        query
                            ? 'No sale with that invoice number.'
                            : 'No sales yet today.'
                    }}
                </p>
                <ul v-else-if="sales" class="divide-y">
                    <li
                        v-for="sale in sales"
                        :key="sale.uuid"
                        class="flex items-center gap-3 py-2"
                        data-test="recent-sale"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="flex items-center gap-2 font-bold">
                                {{ sale.invoice_no }}
                                <span
                                    v-if="sale.status !== 'completed'"
                                    class="rounded bg-amber-500/15 px-1.5 py-0.5 text-xs font-semibold text-amber-700 dark:text-amber-400"
                                    >{{ statusLabel(sale.status) }}</span
                                >
                                <span
                                    v-if="sale.exchange"
                                    class="rounded bg-primary/10 px-1.5 py-0.5 text-xs font-semibold"
                                    data-test="recent-sale-exchange"
                                    >Exchange {{ sale.exchange.number }}</span
                                >
                            </p>
                            <p class="truncate text-sm text-muted-foreground">
                                {{ sale.sold_at }} · {{ sale.cashier }}
                            </p>
                        </div>
                        <span class="font-semibold tabular-nums">{{
                            formatMoney(
                                sale.grand_total,
                                shop.decimals,
                                shop.currency,
                            )
                        }}</span>
                        <Button
                            variant="outline"
                            class="h-11"
                            :disabled="reprinting !== null"
                            data-test="reprint"
                            @click="reprint(sale)"
                        >
                            <Printer class="size-4" />
                            {{
                                reprinting === sale.uuid
                                    ? 'Reprinting...'
                                    : 'Reprint'
                            }}
                        </Button>
                    </li>
                </ul>
            </div>

            <button
                v-if="shiftSummaries"
                type="button"
                class="self-start text-sm font-medium underline underline-offset-4"
                data-test="recent-sales-shift-summaries"
                @click="emit('shift-summaries')"
            >
                Reprint a shift summary
            </button>
        </DialogContent>
    </Dialog>
</template>
