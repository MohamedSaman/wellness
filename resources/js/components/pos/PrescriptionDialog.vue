<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import ScheduleBadge from '@/components/pos/ScheduleBadge.vue';
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

/**
 * The sale's prescription (F7): prescriber (pre-filled with the clinic
 * doctor) and Rx reference, applied to every prescription-only or
 * controlled line without its own; the patient when the controlled-drug
 * register needs one. Opened by F9 instead of payment while a required
 * prescriber is missing, or once per sale when the shop only prompts.
 */
const props = defineProps<{
    open: boolean;
    /** Why the till opened it (payment asked for a prescriber); null when opened with F7. */
    message: string | null;
    /** What still stops payment (prescriber required, patient for a controlled drug); null = nothing. */
    blocking: string | null;
    /** A controlled line with the register on: ask for the patient. */
    needsPatient: boolean;
    /** Opened from payment: the button continues to payment. */
    toPayment: boolean;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean]; continue: [] }>();

const cart = usePosCart();
const { state } = cart;
const prescriber = ref<HTMLInputElement | null>(null);

watch(
    () => props.open,
    async (open) => {
        if (open) {
            await nextTick();
            prescriber.value?.focus();
            prescriber.value?.select();
        }
    },
);

function done(): void {
    if (props.toPayment && props.blocking !== null) {
        return;
    }

    emit('continue');
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            class="max-h-[95vh] gap-4 overflow-y-auto sm:max-w-lg"
            data-test="prescription-dialog"
        >
            <DialogHeader>
                <DialogTitle>Prescription</DialogTitle>
                <DialogDescription>
                    Applies to every prescription-only or controlled line in
                    this sale. A line's own prescriber is set on the line.
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="message || blocking"
                class="rounded-lg p-3 text-sm font-medium"
                :class="
                    blocking
                        ? 'bg-destructive/10 text-destructive'
                        : 'bg-amber-500/10 text-amber-700 dark:text-amber-400'
                "
                role="alert"
                data-test="prescription-message"
            >
                {{ blocking ?? message }}
            </p>

            <form class="grid gap-3" @submit.prevent="done">
                <label class="grid gap-1 text-sm font-medium">
                    Prescriber
                    <input
                        ref="prescriber"
                        v-model="state.prescription.prescriber"
                        type="text"
                        maxlength="150"
                        autocomplete="off"
                        placeholder="Doctor's name"
                        class="h-12 w-full min-w-0 rounded-lg border bg-background px-3 text-base font-normal"
                        data-test="sale-prescriber"
                    />
                </label>
                <label class="grid gap-1 text-sm font-medium">
                    Rx reference (optional)
                    <input
                        v-model="state.prescription.ref"
                        type="text"
                        maxlength="100"
                        autocomplete="off"
                        placeholder="e.g. OPD-1042"
                        class="h-12 w-full min-w-0 rounded-lg border bg-background px-3 text-base font-normal"
                        data-test="sale-prescription-ref"
                    />
                </label>
                <template v-if="needsPatient">
                    <label class="grid gap-1 text-sm font-medium">
                        Patient name (controlled drug)
                        <input
                            v-model="state.customerName"
                            type="text"
                            maxlength="255"
                            autocomplete="off"
                            class="h-12 w-full min-w-0 rounded-lg border bg-background px-3 text-base font-normal"
                            data-test="patient-name"
                        />
                    </label>
                    <label class="grid gap-1 text-sm font-medium">
                        Patient phone (optional)
                        <input
                            v-model="state.customerPhone"
                            type="tel"
                            maxlength="50"
                            autocomplete="off"
                            class="h-12 w-full min-w-0 rounded-lg border bg-background px-3 text-base font-normal"
                        />
                    </label>
                </template>

                <ul
                    v-if="cart.hasRxLines.value"
                    class="grid gap-1 rounded-lg border p-2 text-sm"
                >
                    <template v-for="line in state.lines" :key="line.key">
                        <li
                            v-if="cart.isRxLine(line)"
                            class="flex items-center gap-2"
                        >
                            <ScheduleBadge
                                v-if="line.drug"
                                :schedule="line.drug.schedule"
                            />
                            <span class="min-w-0 flex-1 truncate">{{
                                line.name
                            }}</span>
                            <span
                                :class="
                                    cart.prescriberFor(line) === null
                                        ? 'text-destructive'
                                        : 'text-muted-foreground'
                                "
                                >{{
                                    cart.prescriberFor(line) ?? 'no prescriber'
                                }}</span
                            >
                        </li>
                    </template>
                </ul>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        class="h-12"
                        @click="emit('update:open', false)"
                        >Back to the sale</Button
                    >
                    <Button
                        type="submit"
                        class="h-12"
                        :disabled="toPayment && blocking !== null"
                        data-test="prescription-continue"
                        >{{
                            toPayment ? 'Continue to payment' : 'Done'
                        }}</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
