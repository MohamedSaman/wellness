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
                <DialogTitle class="text-2xl">Sale complete</DialogTitle>
                <DialogDescription
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

            <div v-if="sale" class="grid grid-cols-2 gap-4 text-center">
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
                    >New sale</Button
                >
            </div>
        </DialogContent>
    </Dialog>
</template>
