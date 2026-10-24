<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney } from '@/lib/money';
import type { ReceiptSale, ReceiptShop } from '@/types/pos';

/**
 * The 80 mm receipt itself, for the receipt page and for offline sales.
 * A reprint uses the same template, marked as a duplicate at the top and
 * signed with who reprinted it at the bottom.
 */
const props = defineProps<{ shop: ReceiptShop; sale: ReceiptSale }>();

const money = (minor: number) => formatMoney(minor, props.shop.decimals);

type ReceiptItem = ReceiptSale['items'][number];

const isFullyReturned = (item: ReceiptItem) =>
    item.returnedQuantity != null &&
    Number(item.returnedQuantity) >= Number(item.quantity);

/**
 * "10 Tablet" for a medicine sold loose; a pack is already in the
 * description ("… (Box of 30)"), and other items print the quantity alone,
 * as they always did.
 */
function quantityText(item: ReceiptItem): string {
    const medicine = item.isOpdMedicine || (item.batches ?? []).length > 0;

    return medicine &&
        item.unitName &&
        !item.description.includes(item.unitName)
        ? `${item.quantity} ${item.unitName}`
        : item.quantity;
}

/**
 * An OPD bill prints its services (the consultation) with prices, then one
 * "Medicines" line for the typed amount: the medicines under it by name and
 * quantity without prices (setting 'names'), or nothing more ('totals').
 */
const opd = computed(() => props.sale.billType === 'opd');
const itemised = computed(() =>
    opd.value
        ? props.sale.items.filter((item) => !item.isOpdMedicine)
        : props.sale.items,
);
const medicines = computed(() =>
    opd.value ? props.sale.items.filter((item) => item.isOpdMedicine) : [],
);
const medicinesTotal = computed(
    () =>
        props.sale.medicinesTotal ??
        medicines.value.reduce((sum, item) => sum + (item.lineTotal ?? 0), 0),
);

/**
 * What is printed under each medicine (Receipt settings, branch 9). Missing
 * on catalogs from older servers: batch and expiry, no prescriber, as before.
 */
const options = computed(() => ({
    paperWidth: props.shop.receipt?.paperWidth === 58 ? 58 : 80,
    showBatch: props.shop.receipt?.showBatch ?? true,
    showExpiry: props.shop.receipt?.showExpiry ?? true,
    showPrescriber: props.shop.receipt?.showPrescriber ?? false,
}));

type ReceiptBatch = NonNullable<ReceiptItem['batches']>[number];

/** "Batch P1 exp 01/2027 x 4", with what the shop prints; '' = nothing. */
function batchText(item: ReceiptItem, batch: ReceiptBatch): string {
    const parts: string[] = [];

    if (options.value.showBatch) {
        parts.push(
            batch.batchNo === null
                ? 'Batch to be confirmed'
                : `Batch ${batch.batchNo}`,
        );
    }

    if (options.value.showExpiry && batch.expiry) {
        parts.push(`exp ${batch.expiry}`);
    }

    if (parts.length > 0 && (item.batches ?? []).length > 1) {
        parts.push(`x ${batch.quantity}`);
    }

    return parts.join(' ');
}

const prescriberText = (item: ReceiptItem): string | null =>
    options.value.showPrescriber && item.prescriber
        ? `Prescriber: ${item.prescriber}`
        : null;

// Exempt lines are marked only when the sale was taxed at all.
const markExempt = computed(
    () =>
        props.sale.taxRate > 0 &&
        props.sale.items.some((item) => item.taxExempt),
);
</script>

<template>
    <article
        class="receipt mx-auto bg-white p-[4mm] font-mono text-[11px] leading-snug text-black"
        :style="{ width: `${options.paperWidth}mm` }"
        data-test="receipt"
    >
        <p
            v-if="sale.reprint"
            class="mb-1 border-y-2 border-black py-0.5 text-center text-sm font-bold"
            data-test="receipt-duplicate"
        >
            DUPLICATE RECEIPT
        </p>

        <header class="text-center">
            <img
                v-if="shop.logoUrl"
                :src="shop.logoUrl"
                :alt="shop.name"
                class="mx-auto mb-1 max-h-16 max-w-full"
            />
            <h1 class="text-sm font-bold uppercase">{{ shop.name }}</h1>
            <p v-if="shop.address">{{ shop.address }}</p>
            <p v-if="shop.phone">Tel {{ shop.phone }}</p>
            <p v-if="shop.taxNumber">Tax no. {{ shop.taxNumber }}</p>
            <p v-if="shop.header" class="mt-1 whitespace-pre-line">
                {{ shop.header }}
            </p>
        </header>

        <hr class="my-1 border-dashed border-black" />

        <p
            v-if="sale.status === 'voided'"
            class="my-1 border-2 border-black py-1 text-center text-base font-bold tracking-widest"
            data-test="receipt-voided"
        >
            VOIDED
        </p>
        <dl class="grid grid-cols-[auto_1fr] gap-x-2">
            <dt>Invoice</dt>
            <dd class="text-right font-bold">{{ sale.invoiceNo }}</dd>
            <dt>Date</dt>
            <dd class="text-right">{{ sale.soldAt }}</dd>
            <dt>Cashier</dt>
            <dd class="text-right">{{ sale.cashier }}</dd>
        </dl>

        <hr class="my-1 border-dashed border-black" />

        <ul class="space-y-1">
            <li v-for="(item, index) in itemised" :key="index">
                <p class="font-bold">
                    {{ item.description
                    }}<span v-if="isFullyReturned(item)"> (RETURNED)</span>
                </p>
                <p class="flex justify-between">
                    <span
                        >{{ quantityText(item) }} x
                        {{ money(item.unitPrice ?? 0) }}</span
                    >
                    <span
                        >{{ money(item.lineTotal ?? 0)
                        }}<template v-if="markExempt && item.taxExempt">
                            E</template
                        ></span
                    >
                </p>
                <p v-if="(item.discount ?? 0) > 0" class="flex justify-between">
                    <span>&nbsp;&nbsp;discount</span>
                    <span>-{{ money(item.discount ?? 0) }}</span>
                </p>
                <template
                    v-for="(batch, batchIndex) in item.batches ?? []"
                    :key="`batch-${batchIndex}`"
                >
                    <p v-if="batchText(item, batch)" data-test="receipt-batch">
                        &nbsp;&nbsp;{{ batchText(item, batch) }}
                    </p>
                </template>
                <p v-if="prescriberText(item)" data-test="receipt-prescriber">
                    &nbsp;&nbsp;{{ prescriberText(item) }}
                </p>
                <p
                    v-if="item.returnedQuantity && !isFullyReturned(item)"
                    data-test="receipt-returned"
                >
                    &nbsp;&nbsp;returned {{ item.returnedQuantity }}
                </p>
            </li>
            <li
                v-if="opd && medicines.length > 0"
                data-test="receipt-medicines"
            >
                <p class="flex justify-between font-bold">
                    <span>Medicines</span>
                    <span>{{ money(medicinesTotal) }}</span>
                </p>
                <template v-if="sale.opdReceiptMedicines !== 'totals'">
                    <div
                        v-for="(item, index) in medicines"
                        :key="`medicine-${index}`"
                        data-test="receipt-medicine"
                    >
                        <p>
                            &nbsp;&nbsp;{{ item.description }} x
                            {{ quantityText(item)
                            }}<span v-if="isFullyReturned(item)">
                                (RETURNED)</span
                            >
                        </p>
                        <template
                            v-for="(batch, batchIndex) in item.batches ?? []"
                            :key="`medicine-batch-${batchIndex}`"
                        >
                            <p
                                v-if="batchText(item, batch)"
                                data-test="receipt-batch"
                            >
                                &nbsp;&nbsp;&nbsp;&nbsp;{{
                                    batchText(item, batch)
                                }}
                            </p>
                        </template>
                        <p
                            v-if="prescriberText(item)"
                            data-test="receipt-prescriber"
                        >
                            &nbsp;&nbsp;&nbsp;&nbsp;{{ prescriberText(item) }}
                        </p>
                        <p
                            v-if="
                                item.returnedQuantity && !isFullyReturned(item)
                            "
                        >
                            &nbsp;&nbsp;&nbsp;&nbsp;returned
                            {{ item.returnedQuantity }}
                        </p>
                    </div>
                </template>
            </li>
        </ul>

        <hr class="my-1 border-dashed border-black" />

        <dl class="grid grid-cols-[1fr_auto] gap-x-2">
            <dt>Subtotal</dt>
            <dd class="text-right">{{ money(sale.subtotal) }}</dd>
            <template v-if="sale.discountTotal > 0">
                <dt>Discounts</dt>
                <dd class="text-right">-{{ money(sale.discountTotal) }}</dd>
            </template>
            <template v-if="sale.taxTotal > 0">
                <dt>
                    Tax {{ sale.taxRate }}%{{
                        sale.pricesIncludeTax ? ' (incl.)' : ''
                    }}
                </dt>
                <dd class="text-right">{{ money(sale.taxTotal) }}</dd>
            </template>
            <dt
                v-if="markExempt"
                class="col-span-2"
                data-test="receipt-exempt-note"
            >
                E = tax exempt
            </dt>
            <dt class="text-sm font-bold">TOTAL {{ shop.currency }}</dt>
            <dd class="text-right text-sm font-bold">
                {{ money(sale.grandTotal) }}
            </dd>
        </dl>

        <hr class="my-1 border-dashed border-black" />

        <dl class="grid grid-cols-[1fr_auto] gap-x-2">
            <template v-for="(payment, index) in sale.payments" :key="index">
                <dt>
                    {{ payment.method
                    }}<template v-if="payment.reference">
                        ref {{ payment.reference }}</template
                    >
                </dt>
                <dd class="text-right">{{ money(payment.amount) }}</dd>
            </template>
            <dt>Change</dt>
            <dd class="text-right">{{ money(sale.changeDue) }}</dd>
            <template v-if="sale.refundedTotal">
                <dt>Refunded</dt>
                <dd class="text-right">-{{ money(sale.refundedTotal) }}</dd>
            </template>
        </dl>

        <p v-if="shop.footer" class="mt-2 text-center whitespace-pre-line">
            {{ shop.footer }}
        </p>

        <p
            v-if="sale.reprint"
            class="mt-2 border-t border-dashed border-black pt-1 text-center"
            data-test="receipt-reprinted"
        >
            Reprinted on {{ sale.reprint.printedAt }} by
            {{ sale.reprint.printedBy }}
        </p>
    </article>
</template>
