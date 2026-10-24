<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { FileText, Printer } from '@lucide/vue';
import { computed, onMounted } from 'vue';
import ReceiptView from '@/components/pos/ReceiptView.vue';
import ThermalReceipt from '@/components/pos/ThermalReceipt.vue';
import { Button } from '@/components/ui/button';
import { receiptPrinter } from '@/lib/receiptPrinter';
import type { ReceiptSale, ReceiptShop, ThermalReceiptData } from '@/types/pos';

/**
 * A printable receipt: a sale's receipt or its reprint, a refund slip or a
 * void slip. A pharmacy shop's (and every refund and void slip) comes laid
 * out for its thermal paper (`thermal`); a retail shop's sale receipt is the
 * HTML receipt it always had (`sale`).
 */
const props = defineProps<{
    title?: string;
    shop: ReceiptShop;
    sale: ReceiptSale | null;
    thermal?: ThermalReceiptData | null;
    invoiceUrl?: string | null;
}>();

const paperWidth = computed(
    () => props.thermal?.paperWidth ?? props.shop.receipt?.paperWidth ?? 80,
);

const print = () =>
    receiptPrinter().print({ escpos: props.thermal?.escpos ?? null });

onMounted(() => {
    // The paper's width for the print dialog (80 or 58 mm, set per shop).
    const page = document.createElement('style');
    page.textContent = `@media print { @page { size: ${paperWidth.value}mm auto; margin: 0; } }`;
    document.head.appendChild(page);

    // Opened by "Print receipt" on the checkout screen, or by a reprint.
    if (new URLSearchParams(window.location.search).get('print') === '1') {
        // A window the till opened closes once printed, so a kiosk till
        // (Chrome --kiosk --kiosk-printing, docs/deploy/TILLS.md) is not
        // left with the receipt on top of the checkout screen.
        if (window.opener !== null) {
            window.addEventListener('afterprint', () => window.close(), {
                once: true,
            });
        }

        setTimeout(() => void print(), 300);
    }
});
</script>

<template>
    <Head
        :title="
            title ??
            `${sale?.reprint ? 'Duplicate receipt' : 'Receipt'} ${sale?.invoiceNo ?? ''}`
        "
    />

    <div
        class="receipt-screen min-h-dvh bg-neutral-100 p-4 dark:bg-neutral-900"
    >
        <div
            class="no-print mx-auto mb-4 flex max-w-[80mm] justify-center gap-2"
        >
            <Button
                class="h-12 text-base"
                data-test="print-button"
                @click="print"
            >
                <Printer class="size-5" /> Print
            </Button>
            <Button
                v-if="thermal && invoiceUrl"
                as="a"
                :href="invoiceUrl"
                target="_blank"
                variant="outline"
                class="h-12 text-base"
                data-test="invoice-button"
            >
                <FileText class="size-5" /> A4 invoice
            </Button>
        </div>

        <ThermalReceipt
            v-if="thermal"
            :lines="thermal.lines"
            :columns="thermal.columns"
            :paper-width="thermal.paperWidth"
            :logo-url="shop.logoUrl"
        />
        <ReceiptView v-else-if="sale" :shop="shop" :sale="sale" />
    </div>
</template>

<style>
@media print {
    html,
    body {
        background: #fff !important;
    }

    .no-print {
        display: none !important;
    }

    .receipt-screen {
        padding: 0 !important;
        background: #fff !important;
    }

    .receipt {
        margin: 0 !important;
    }

    .receipt:not(.thermal-receipt) {
        padding: 2mm 3mm !important;
    }
}
</style>
