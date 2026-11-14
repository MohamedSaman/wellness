<script setup lang="ts">
import { Printer, WifiOff } from '@lucide/vue';
import { ref, watch } from 'vue';
import ShiftReprintController from '@/actions/App/Http/Controllers/Pos/ShiftReprintController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useOfflinePos } from '@/composables/useOfflinePos';
import { ApiError, getJson, queryString } from '@/lib/http';
import { formatMoney } from '@/lib/money';
import { reprintShiftSummary } from '@/lib/receipt/reprint';
import type { ClosedShift, PosShop, PosTill } from '@/types/pos';

/**
 * Shift summaries (pharmacy; docs/PHARMACY.md, branch 17): this till's
 * shifts closed in the last 7 days that the cashier may print (their own,
 * or every one with shifts.manage), each with Reprint. The reprint is
 * logged and printed marked REPRINT, so it needs a connection.
 */
const props = defineProps<{ open: boolean; till: PosTill; shop: PosShop }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const offlinePos = useOfflinePos();
const shifts = ref<ClosedShift[] | null>(null);
const days = ref(7);
const error = ref<string | null>(null);
const reprinting = ref<string | null>(null);
let controller: AbortController | undefined;

/** "2026-10-18 18:00" → "18/10/2026 18:00". */
const shownDate = (local: string) =>
    local.replace(/^(\d{4})-(\d{2})-(\d{2})/, '$3/$2/$1');

async function load(): Promise<void> {
    controller?.abort();
    controller = new AbortController();

    try {
        const response = await getJson<{
            shifts: ClosedShift[];
            days: number;
        }>(
            ShiftReprintController.index.url() +
                queryString({ device: props.till.uuid }),
            controller.signal,
        );
        shifts.value = response.shifts;
        days.value = response.days;
        error.value = null;
    } catch (caught) {
        if (caught instanceof DOMException && caught.name === 'AbortError') {
            return;
        }

        error.value =
            caught instanceof ApiError && caught.status !== 0
                ? caught.message
                : 'Could not load the shifts. Reprinting a shift summary needs a connection.';
    }
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            shifts.value = null;
            error.value = null;

            if (!offlinePos.isOffline.value) {
                void load();
            }
        } else {
            controller?.abort();
        }
    },
);

async function reprint(shift: ClosedShift): Promise<void> {
    if (reprinting.value !== null) {
        return;
    }

    reprinting.value = shift.uuid;
    error.value = null;

    try {
        await reprintShiftSummary(shift.uuid, props.till.uuid);
    } catch (caught) {
        error.value =
            caught instanceof ApiError && caught.status !== 0
                ? caught.message
                : 'The shift summary could not be reprinted. Reprinting needs a connection.';
    } finally {
        reprinting.value = null;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="sm:max-w-2xl" data-test="shift-summaries">
            <DialogHeader>
                <DialogTitle class="text-xl">Reprint shift summary</DialogTitle>
                <DialogDescription
                    >Shifts closed on {{ till.code }} in the last
                    {{ days }} days. Reprints are marked REPRINT and
                    logged.</DialogDescription
                >
            </DialogHeader>

            <p
                v-if="offlinePos.isOffline.value"
                class="flex items-start gap-2 rounded-lg bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-400"
                data-test="shift-summaries-offline"
            >
                <WifiOff class="mt-0.5 size-4 shrink-0" />
                <span
                    >Offline: reprinting a shift summary needs a connection. Try
                    again when this till is back online.</span
                >
            </p>

            <p
                v-if="error"
                class="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive"
                role="alert"
            >
                {{ error }}
            </p>

            <div
                v-if="!offlinePos.isOffline.value"
                class="max-h-[60dvh] min-h-40 overflow-y-auto"
            >
                <p
                    v-if="shifts === null && !error"
                    class="py-8 text-center text-muted-foreground"
                >
                    Loading...
                </p>
                <p
                    v-else-if="shifts && shifts.length === 0"
                    class="py-8 text-center text-muted-foreground"
                    data-test="shift-summaries-empty"
                >
                    No shift closed on this till in the last {{ days }} days.
                </p>
                <ul v-else-if="shifts" class="divide-y">
                    <li
                        v-for="shift in shifts"
                        :key="shift.uuid"
                        class="flex items-center gap-3 py-2"
                        data-test="shift-summary-row"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="font-bold">
                                Closed {{ shownDate(shift.closed_at) }}
                            </p>
                            <p class="truncate text-sm text-muted-foreground">
                                {{ shift.cashier }}
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="text-xs text-muted-foreground">
                                Net sales
                            </p>
                            <p class="font-semibold tabular-nums">
                                {{
                                    formatMoney(
                                        shift.net,
                                        shop.decimals,
                                        shop.currency,
                                    )
                                }}
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            class="h-11 shrink-0"
                            :disabled="reprinting !== null"
                            data-test="shift-summary-reprint"
                            @click="reprint(shift)"
                        >
                            <Printer class="size-4" />
                            {{
                                reprinting === shift.uuid
                                    ? 'Reprinting...'
                                    : 'Reprint'
                            }}
                        </Button>
                    </li>
                </ul>
            </div>
        </DialogContent>
    </Dialog>
</template>
