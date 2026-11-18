<script setup lang="ts">
import { CircleCheck, Printer } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatMoney } from '@/lib/money';
import type { CompletedSale, PosShop } from '@/types/pos';

const props = defineProps<{ sale: CompletedSale | null; shop: PosShop }>();
const emit = defineEmits<{ close: []; printOffline: [] }>();

function print(): void {
    if (props.sale?.receipt_url === null) {
        emit('printOffline');
    } else if (props.sale) {
        // The receipt page prints itself when opened with ?print=1.
        window.open(
            `${props.sale.receipt_url}?print=1`,
            '_blank',
            'width=420,height=720',
        );
    }
}
</script>

<template>
    <Dialog
        :open="sale !== null"
        @update:open="(open) => !open && emit('close')"
    >
        <DialogContent class="sm:max-w-md" data-test="sale-done">
            <DialogHeader class="items-center text-center">
                <CircleCheck class="size-14 text-emerald-600" />
                <DialogTitle class="text-2xl">{{
                    sale?.exchange
                        ? sale.exchange.kind === 'exchange'
                            ? 'Exchange complete'
                            : 'Return complete'
                        : 'Sale complete'
                }}</DialogTitle>
                <DialogDescription v-if="sale?.exchange"
                    >{{ sale.exchange.number }} ·
                    {{ sale.exchange.original_invoice_no
                    }}<template v-if="sale.exchange.new_invoice_no">
                        → new bill {{ sale.exchange.new_invoice_no }}</template
                    ></DialogDescription
                >
                <DialogDescription v-else
                    >Invoice {{ sale?.invoice_no }}</DialogDescription
                >
                <p
                    v-if="sale && sale.receipt_url === null"
                    class="rounded-lg bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-400"
                    data-test="saved-offline"
                >
                    Saved on this till. It will sync when the connection is
                    back.
                </p>
            </DialogHeader>

            <div
                v-if="sale?.exchange"
                class="grid grid-cols-2 gap-4 text-center"
                data-test="exchange-done"
            >
                <div>
                    <p class="text-sm text-muted-foreground">Return credit</p>
                    <p class="text-2xl font-bold tabular-nums">
                        {{
                            formatMoney(
                                sale.exchange.credit,
                                shop.decimals,
                                shop.currency,
                            )
                        }}
                    </p>
                </div>
                <div v-if="sale.exchange.cash_refunded > 0">
                    <p class="text-sm text-muted-foreground">Refund in cash</p>
                    <p
                        class="text-2xl font-bold text-emerald-600 tabular-nums dark:text-emerald-400"
                        data-test="exchange-done-refund"
                    >
                        {{
                            formatMoney(
                                sale.exchange.cash_refunded,
                                shop.decimals,
                                shop.currency,
                            )
                        }}
                    </p>
                </div>
                <div v-else>
                    <p class="text-sm text-muted-foreground">
                        Patient paid<template v-if="sale.change_due > 0">
                            · change
                            {{
                                formatMoney(
                                    sale.change_due,
                                    shop.decimals,
                                    shop.currency,
                                )
                            }}</template
                        >
                    </p>
                    <p
                        class="text-2xl font-bold tabular-nums"
                        data-test="exchange-done-paid"
                    >
                        {{
                            formatMoney(
                                sale.exchange.patient_paid,
                                shop.decimals,
                                shop.currency,
                            )
                        }}
                    </p>
                </div>
            </div>

            <div v-else-if="sale" class="grid grid-cols-2 gap-4 text-center">
                <div>
                    <p class="text-sm text-muted-foreground">Total</p>
                    <p class="text-2xl font-bold tabular-nums">
                        {{
                            formatMoney(
                                sale.grand_total,
                                shop.decimals,
                                shop.currency,
                            )
                        }}
                    </p>
                </div>
                <div>
                    <p class="text-sm text-muted-foreground">Change given</p>
                    <p
                        class="text-2xl font-bold text-emerald-600 tabular-nums dark:text-emerald-400"
                    >
                        {{
                            formatMoney(
                                sale.change_due,
                                shop.decimals,
                                shop.currency,
                            )
                        }}
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <Button
                    variant="outline"
                    class="h-14 text-base"
                    data-test="print-receipt"
                    @click="print"
                >
                    <Printer class="size-5" /> Print receipt
                </Button>
                <Button
                    class="h-14 text-base"
                    data-test="new-sale"
                    @click="emit('close')"
                    >{{ sale?.exchange ? 'Done' : 'New sale' }}</Button
                >
            </div>
        </DialogContent>
    </Dialog>
</template>
