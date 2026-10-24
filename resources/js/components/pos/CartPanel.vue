<script setup lang="ts">
import {
    FileText,
    Minus,
    Percent,
    Plus,
    Trash2,
    TriangleAlert,
} from '@lucide/vue';
import { reactive } from 'vue';
import ScheduleBadge from '@/components/pos/ScheduleBadge.vue';
import { Button } from '@/components/ui/button';
import { usePosCart } from '@/composables/usePosCart';
import type { CartLineState } from '@/composables/usePosCart';
import { formatMoney, formatQuantity, toInputValue } from '@/lib/money';
import {
    baseUnitName,
    expiryClasses,
    expiryTone,
    inPacks,
    today,
} from '@/lib/pharmacyDisplay';
import type { Quote, QuoteLine } from '@/types/pos';

const props = defineProps<{
    lines: CartLineState[];
    quote: Quote | null;
    stale: boolean;
    decimals: number;
    currency: string;
    /** The pharmacy module: schedule badges, batches and prescriptions on the lines. */
    pharmacy?: boolean;
    /** OPD bill: medicine lines show no prices and nothing takes a discount. */
    opdBill?: boolean;
    /** pos.batch_override: the cashier may choose a line's batch. */
    canBatchOverride?: boolean;
    /** pos.price_override: the cashier may type the price of an item that allows it. */
    canOverridePrice?: boolean;
    /** The cashier may give a discount (a limit above 0, or no limit); else no line discount. */
    canDiscount?: boolean;
}>();

const emit = defineEmits<{
    step: [line: CartLineState, delta: number];
    quantity: [line: CartLineState, quantity: number];
    discount: [line: CartLineState, value: string];
    remove: [line: CartLineState];
}>();

const cart = usePosCart();

const discountOpen = reactive<Record<string, boolean>>({});
const rxOpen = reactive<Record<string, boolean>>({});
/** Why the last change to a line was refused (part units, a unit not sold here). */
const lineMessage = reactive<Record<string, string>>({});

/** The quote's line for a cart line (the quote keeps the cart's order). */
function quoteLine(index: number): QuoteLine | null {
    const line = props.quote?.lines[index];

    return line && line.product_variant_id === props.lines[index]?.variantId
        ? line
        : null;
}

function lineTotal(index: number): string {
    const line = props.quote?.lines[index];

    // An OPD medicine line has no total of its own on the quote (null): it is in Medicines.
    return line && line.line_total !== null
        ? formatMoney(line.line_total, props.decimals, props.currency)
        : '…';
}

/** "12.50 each", or "450.00 / Box of 30" for a pack. */
function unitPrice(line: CartLineState, index: number): string {
    const quoted = props.quote?.lines[index];

    if (!quoted) {
        return '';
    }

    const price = formatMoney(quoted.unit_price, props.decimals);

    return line.unitId === null ? `${price} each` : `${price} / ${line.unit}`;
}

function isOpdMedicine(line: CartLineState): boolean {
    return !!props.opdBill && line.trackStock;
}

function sellableUnits(line: CartLineState) {
    return line.units.filter((unit) => unit.sellable);
}

function onUnit(line: CartLineState, event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    const refused = cart.setUnit(line, value === 'base' ? null : Number(value));

    if (refused !== null) {
        lineMessage[line.key] = refused;
        (event.target as HTMLSelectElement).value = String(
            line.unitId ?? 'base',
        );
    } else {
        delete lineMessage[line.key];
    }
}

function onQuantity(line: CartLineState, event: Event): void {
    const input = event.target as HTMLInputElement;
    const value = Number(input.value);

    if (!Number.isFinite(value) || value <= 0) {
        input.value = String(line.quantity);

        return;
    }

    // The server refuses part units too (StockService::assertWholeUnits()).
    if (line.wholeUnitsOnly && !Number.isInteger(value)) {
        lineMessage[line.key] =
            `${line.sku} is sold in whole units only: enter a whole number of ${line.unit}.`;
        input.value = String(line.quantity);

        return;
    }

    delete lineMessage[line.key];
    emit('quantity', line, value);
}

function overStock(line: CartLineState): boolean {
    return line.stock !== null && cart.baseQuantity(line) > line.stock;
}

/**
 * What is left at this till: in packs + units for an item with packs sold
 * here, with the base unit's name for a medicine, and as always (just the
 * number) for anything else, as in search.
 */
function stockLeft(line: CartLineState): string {
    const stock = line.stock ?? 0;

    if (line.units.length === 0) {
        return formatQuantity(stock);
    }

    const base = baseUnitName(line.units, line.unit);
    const packs = inPacks(stock, line.units, base);

    if (packs !== null) {
        return packs;
    }

    return props.pharmacy && line.drug !== null
        ? `${formatQuantity(stock)} ${base}`
        : formatQuantity(stock);
}

/** Batches the cashier may choose for the line: none expired. */
function batchOptions(line: CartLineState) {
    const day = today();

    return line.batches.filter(
        (batch) =>
            batch.quantity > 0 &&
            (batch.expiry === null || batch.expiry >= day),
    );
}

function onBatch(line: CartLineState, event: Event): void {
    const value = (event.target as HTMLSelectElement).value;

    cart.pickBatch(line, value === '' ? null : Number(value));
}

function onPrice(line: CartLineState, event: Event): void {
    cart.setPrice(line, (event.target as HTMLInputElement).value);
}

function showRx(line: CartLineState): boolean {
    return !!props.pharmacy && cart.isRxLine(line);
}

function sameAsSale(line: CartLineState): void {
    cart.setPrescriber(line, null);
    cart.setPrescriptionRef(line, null);
}
</script>

<template>
    <ul
        class="grid min-h-0 flex-1 content-start gap-2 overflow-y-auto pr-1"
        data-test="cart-lines"
    >
        <li
            v-if="lines.length === 0"
            class="grid h-full place-items-center p-8 text-center text-muted-foreground"
        >
            Scan an item or search to start the sale.
        </li>

        <li
            v-for="(line, index) in lines"
            :key="line.key"
            class="rounded-xl border bg-card p-3"
            :class="{ 'opacity-70': stale }"
            data-test="cart-line"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p
                        class="flex items-center gap-2 text-base leading-tight font-semibold"
                    >
                        <span class="truncate"
                            >{{ line.name
                            }}<template v-if="line.variantName">
                                · {{ line.variantName }}</template
                            ></span
                        >
                        <ScheduleBadge
                            v-if="pharmacy && line.drug"
                            :schedule="line.drug.schedule"
                        />
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ line.sku
                        }}<template v-if="pharmacy && line.drug?.strength">
                            · {{ line.drug.strength }}</template
                        ><template v-if="pharmacy && line.drug">
                            · {{ line.drug.dosage_form }}</template
                        ><template
                            v-if="
                                !isOpdMedicine(line) && unitPrice(line, index)
                            "
                        >
                            · {{ unitPrice(line, index) }}</template
                        >
                    </p>
                </div>
                <p
                    v-if="isOpdMedicine(line)"
                    class="shrink-0 text-sm text-muted-foreground"
                    data-test="opd-medicine"
                >
                    In Medicines
                </p>
                <p v-else class="shrink-0 text-lg font-bold tabular-nums">
                    {{ lineTotal(index) }}
                </p>
            </div>

            <div class="mt-2 flex flex-wrap items-center gap-2">
                <Button
                    variant="outline"
                    size="icon-lg"
                    class="size-12"
                    aria-label="Decrease quantity"
                    @click="emit('step', line, -1)"
                >
                    <Minus class="size-5" />
                </Button>
                <input
                    :value="formatQuantity(line.quantity)"
                    type="number"
                    :inputmode="line.wholeUnitsOnly ? 'numeric' : 'decimal'"
                    :step="line.wholeUnitsOnly ? 1 : 'any'"
                    min="0"
                    aria-label="Quantity"
                    class="h-12 w-24 rounded-lg border bg-background px-2 text-center text-lg font-semibold tabular-nums"
                    data-test="line-quantity"
                    @change="onQuantity(line, $event)"
                    @keydown.enter.prevent="
                        ($event.target as HTMLInputElement).blur()
                    "
                />
                <Button
                    variant="outline"
                    size="icon-lg"
                    class="size-12"
                    aria-label="Increase quantity"
                    @click="emit('step', line, 1)"
                >
                    <Plus class="size-5" />
                </Button>

                <select
                    v-if="sellableUnits(line).length > 1"
                    :value="line.unitId ?? 'base'"
                    aria-label="Unit"
                    class="h-12 max-w-40 rounded-lg border bg-background px-2 text-base"
                    data-test="line-unit"
                    @change="onUnit(line, $event)"
                >
                    <option
                        v-for="unit in sellableUnits(line)"
                        :key="unit.id"
                        :value="unit.is_base ? 'base' : unit.id"
                    >
                        {{ unit.name }}
                    </option>
                </select>
                <span
                    v-else-if="pharmacy && line.units.length > 0"
                    class="text-sm text-muted-foreground"
                    >{{ line.unit }}</span
                >

                <label
                    v-if="canOverridePrice && line.allowPriceOverride"
                    class="flex items-center gap-1.5 text-sm text-muted-foreground"
                >
                    Price
                    <input
                        :value="line.price"
                        type="text"
                        inputmode="decimal"
                        :placeholder="
                            line.listPrice === null
                                ? '0.00'
                                : toInputValue(line.listPrice, decimals)
                        "
                        aria-label="Price"
                        class="h-12 w-28 rounded-lg border bg-background px-3 text-right text-base text-foreground tabular-nums"
                        data-test="line-price"
                        @input="onPrice(line, $event)"
                        @keydown.enter.prevent="
                            ($event.target as HTMLInputElement).blur()
                        "
                    />
                </label>

                <Button
                    v-if="!opdBill && canDiscount !== false"
                    :variant="line.lineDiscount ? 'secondary' : 'ghost'"
                    class="ml-auto h-12 px-3"
                    aria-label="Line discount"
                    @click="discountOpen[line.key] = !discountOpen[line.key]"
                >
                    <Percent class="size-4" />
                    <span v-if="line.lineDiscount"
                        >-{{ line.lineDiscount }}</span
                    >
                </Button>
                <Button
                    variant="ghost"
                    size="icon-lg"
                    class="size-12 text-destructive"
                    :class="{ 'ml-auto': opdBill }"
                    aria-label="Remove item"
                    @click="emit('remove', line)"
                >
                    <Trash2 class="size-5" />
                </Button>
            </div>

            <div
                v-if="
                    discountOpen[line.key] && !opdBill && canDiscount !== false
                "
                class="mt-2 flex items-center gap-2"
            >
                <label
                    class="text-sm text-muted-foreground"
                    :for="`discount-${line.key}`"
                    >Discount on this line</label
                >
                <input
                    :id="`discount-${line.key}`"
                    :value="line.lineDiscount"
                    type="text"
                    inputmode="decimal"
                    placeholder="0.00"
                    class="h-11 w-32 rounded-lg border bg-background px-3 text-right text-base tabular-nums"
                    data-test="line-discount"
                    @input="
                        emit(
                            'discount',
                            line,
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
            </div>

            <div
                v-if="line.trackBatches"
                class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm"
                data-test="line-batches"
            >
                <template v-if="quoteLine(index)?.batches?.length">
                    <span
                        v-for="batch in quoteLine(index)?.batches ?? []"
                        :key="batch.batch_id"
                    >
                        Batch {{ batch.batch_no }}
                        <span :class="expiryClasses[expiryTone(batch.expiry)]"
                            >exp {{ batch.expiry ?? '—' }}</span
                        ><template
                            v-if="(quoteLine(index)?.batches ?? []).length > 1"
                        >
                            × {{ formatQuantity(batch.quantity) }}</template
                        >
                    </span>
                </template>
                <span
                    v-if="(quoteLine(index)?.shortfall ?? 0) > 0"
                    class="flex items-center gap-1 font-medium text-amber-600 dark:text-amber-400"
                    data-test="batch-shortfall"
                >
                    <TriangleAlert class="size-4" />
                    {{ formatQuantity(quoteLine(index)?.shortfall ?? 0) }}
                    {{ baseUnitName(line.units, line.unit) }} not in a batch
                    that can be sold
                </span>

                <select
                    v-if="canBatchOverride"
                    :value="line.batchId ?? ''"
                    aria-label="Batch"
                    class="ml-auto h-10 max-w-60 rounded-lg border bg-background px-2 text-sm"
                    data-test="line-batch"
                    @change="onBatch(line, $event)"
                >
                    <option value="">First expiry first (auto)</option>
                    <option
                        v-for="batch in batchOptions(line)"
                        :key="batch.id"
                        :value="batch.id"
                    >
                        {{ batch.batch_no }} · exp {{ batch.expiry ?? '—' }} ·
                        {{ formatQuantity(batch.quantity) }} left
                    </option>
                </select>
            </div>

            <div
                v-if="showRx(line)"
                class="mt-2 text-sm"
                data-test="line-prescription"
            >
                <button
                    type="button"
                    class="flex items-center gap-1.5 rounded-md text-left hover:underline"
                    :class="
                        cart.prescriberFor(line) === null
                            ? 'font-medium text-destructive'
                            : 'text-muted-foreground'
                    "
                    :aria-expanded="!!rxOpen[line.key]"
                    @click="rxOpen[line.key] = !rxOpen[line.key]"
                >
                    <FileText class="size-4" />
                    <template v-if="cart.prescriberFor(line) !== null">
                        Prescriber {{ cart.prescriberFor(line)
                        }}<template v-if="cart.prescriptionRefFor(line)">
                            · Rx {{ cart.prescriptionRefFor(line) }}</template
                        >
                    </template>
                    <template v-else>No prescriber entered</template>
                </button>
                <div
                    v-if="rxOpen[line.key]"
                    class="mt-2 flex flex-wrap items-center gap-2"
                >
                    <input
                        :value="
                            line.prescriber ??
                            cart.state.prescription.prescriber
                        "
                        type="text"
                        maxlength="150"
                        placeholder="Prescriber"
                        aria-label="Prescriber for this line"
                        class="h-10 min-w-0 flex-1 rounded-lg border bg-background px-3 text-base"
                        data-test="line-prescriber"
                        @input="
                            cart.setPrescriber(
                                line,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <input
                        :value="
                            line.prescriptionRef ?? cart.state.prescription.ref
                        "
                        type="text"
                        maxlength="100"
                        placeholder="Rx reference"
                        aria-label="Prescription reference for this line"
                        class="h-10 w-36 rounded-lg border bg-background px-3 text-base"
                        @input="
                            cart.setPrescriptionRef(
                                line,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <Button
                        v-if="
                            line.prescriber !== null ||
                            line.prescriptionRef !== null
                        "
                        variant="ghost"
                        class="h-10"
                        @click="sameAsSale(line)"
                        >Same as sale</Button
                    >
                </div>
            </div>

            <p
                v-if="lineMessage[line.key]"
                class="mt-2 text-sm font-medium text-destructive"
                role="alert"
                data-test="line-message"
            >
                {{ lineMessage[line.key] }}
            </p>

            <p
                v-if="overStock(line)"
                class="mt-2 flex items-center gap-1.5 text-sm font-medium text-amber-600 dark:text-amber-400"
                data-test="stock-warning"
            >
                <TriangleAlert class="size-4" />
                Only {{ stockLeft(line) }} in stock.
            </p>
        </li>
    </ul>
</template>
