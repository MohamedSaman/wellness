<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import StockRequestController from '@/actions/App/Http/Controllers/Pos/StockRequestController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useOfflinePos } from '@/composables/useOfflinePos';
import { ApiError, postJson } from '@/lib/http';
import { formatQuantity } from '@/lib/money';
import { unitsOf } from '@/lib/offline/pricing';
import type { PosItem, PosLocation } from '@/types/pos';

/**
 * Asks another location for an item found in the till's search (search by
 * location, docs/PHARMACY.md "Till search by location"): a one-line stock
 * request through the existing Stock requests flow (POST
 * /pos/stock-requests, StockRequestService::create()). Online only, like
 * the Stock requests page: there is no outbox for requests.
 */
const props = defineProps<{
    /** The item to ask for; null = closed. */
    item: PosItem | null;
    /** The location to ask first, when it holds the item. */
    preferredFrom: number | null;
    /** The till's location: the stock is asked for here. */
    tillLocation: PosLocation;
    /** The selling locations, in order. */
    locations: PosLocation[];
}>();

const emit = defineEmits<{
    close: [];
    requested: [number: string, from: string];
}>();

const offline = useOfflinePos();
const quantity = ref('1');
const unitId = ref<number | null>(null);
const fromId = ref<number | null>(null);
const error = ref<string | null>(null);
const sending = ref(false);
const input = ref<HTMLInputElement | null>(null);

/** Other selling locations holding the item, in the shop's order. */
const sources = computed(() =>
    props.locations
        .filter((location) => location.id !== props.tillLocation.id)
        .map((location) => ({
            ...location,
            stock:
                props.item?.stock_by_location?.find(
                    (level) => level.id === location.id,
                )?.stock ?? 0,
        }))
        .filter((location) => location.stock > 0),
);

const units = computed(() => (props.item ? unitsOf(props.item) : []));

watch(
    () => props.item,
    async (item) => {
        if (item === null) {
            return;
        }

        quantity.value = '1';
        error.value = null;
        // A pack sold here by default is what the counter thinks in; else the base unit.
        unitId.value =
            units.value.find((unit) => unit.is_default_sale)?.id ??
            units.value.find((unit) => unit.is_base)?.id ??
            units.value[0]?.id ??
            null;
        fromId.value =
            sources.value.find((source) => source.id === props.preferredFrom)
                ?.id ??
            sources.value[0]?.id ??
            null;
        await nextTick();
        input.value?.focus();
        input.value?.select();
    },
    { immediate: true },
);

function unitLabel(unit: { name: string; factor: number; is_base: boolean }) {
    return unit.is_base || props.item === null
        ? unit.name
        : `${unit.name} of ${formatQuantity(unit.factor)}`;
}

async function send(): Promise<void> {
    const item = props.item;
    const amount = Number(quantity.value);

    if (item === null || sending.value) {
        return;
    }

    if (offline.isOffline.value) {
        error.value =
            'Stock requests need a connection. Try again when the till is online.';

        return;
    }

    if (!Number.isFinite(amount) || amount <= 0) {
        error.value = 'Enter how much to ask for.';

        return;
    }

    if (fromId.value === null) {
        error.value = 'No other location has this item.';

        return;
    }

    sending.value = true;
    error.value = null;

    try {
        const response = await postJson<{ request: { number: string } }>(
            StockRequestController.store.url(),
            {
                from: fromId.value,
                for: props.tillLocation.id,
                lines: [
                    {
                        variant_id: item.variant_id,
                        unit_id: unitId.value,
                        quantity: amount,
                    },
                ],
            },
        );
        const from =
            props.locations.find((location) => location.id === fromId.value)
                ?.name ?? '';
        emit('requested', response.request.number, from);
    } catch (failure) {
        error.value =
            failure instanceof ApiError && failure.status === 0
                ? 'Stock requests need a connection. Try again when the till is online.'
                : failure instanceof ApiError
                  ? failure.message
                  : 'The request was not sent.';
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <Dialog
        :open="item !== null"
        @update:open="(open: boolean) => !open && emit('close')"
    >
        <DialogContent class="sm:max-w-md" data-test="stock-request-dialog">
            <DialogHeader>
                <DialogTitle>Request to {{ tillLocation.name }}</DialogTitle>
                <DialogDescription>
                    {{ item?.name
                    }}<template v-if="item?.variant_name">
                        · {{ item.variant_name }}</template
                    >
                    ({{ item?.sku }}). The request goes to the location that
                    sends it; follow it in Stock requests.
                </DialogDescription>
            </DialogHeader>

            <form class="grid min-w-0 gap-3" @submit.prevent="send">
                <label class="grid min-w-0 gap-1 text-sm font-medium">
                    How many
                    <div class="flex min-w-0 gap-2">
                        <input
                            ref="input"
                            v-model="quantity"
                            type="text"
                            inputmode="decimal"
                            class="h-12 min-w-0 flex-1 rounded-lg border bg-background px-3 text-right text-xl font-bold tabular-nums"
                            data-test="stock-request-quantity"
                        />
                        <select
                            v-if="units.length > 1"
                            v-model="unitId"
                            class="h-12 w-40 min-w-0 shrink-0 rounded-lg border bg-background px-3 text-base"
                            aria-label="Unit"
                            data-test="stock-request-unit"
                        >
                            <option
                                v-for="unit in units"
                                :key="unit.id ?? 'base'"
                                :value="unit.id"
                            >
                                {{ unitLabel(unit) }}
                            </option>
                        </select>
                        <span
                            v-else
                            class="self-center text-sm text-muted-foreground"
                            >{{ units[0]?.name ?? item?.unit }}</span
                        >
                    </div>
                </label>

                <label class="grid min-w-0 gap-1 text-sm font-medium">
                    From
                    <select
                        v-model="fromId"
                        class="h-12 w-full min-w-0 rounded-lg border bg-background px-3 text-base"
                        data-test="stock-request-from"
                    >
                        <option
                            v-for="source in sources"
                            :key="source.id"
                            :value="source.id"
                        >
                            {{ source.name }} (has
                            {{ formatQuantity(source.stock) }}
                            {{ item?.unit }})
                        </option>
                    </select>
                </label>

                <p
                    v-if="error"
                    class="rounded-lg bg-destructive/10 p-3 text-sm font-medium text-destructive"
                    role="alert"
                    data-test="stock-request-error"
                >
                    {{ error }}
                </p>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        class="h-12"
                        @click="emit('close')"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        class="h-12"
                        :disabled="sending || sources.length === 0"
                        data-test="stock-request-send"
                        >Send request</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
