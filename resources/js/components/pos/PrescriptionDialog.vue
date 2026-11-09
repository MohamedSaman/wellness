<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import PatientPicker from '@/components/pos/PatientPicker.vue';
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
import type { SalePatient } from '@/types/pos';

/**
 * The sale's patient and prescription (F7; docs/PHARMACY.md §4.10 and
 * branch 13). The patient comes first: found by name or added with name and
 * age, required or skippable as the shop says (patient_on_sale). Then, when
 * the sale has a prescription-only or controlled line, the prescriber
 * (pre-filled with the clinic doctor) and Rx reference, applied to every
 * such line without its own; and the patient's phone when the
 * controlled-drug register needs the patient. F9 opens it before payment:
 * once per sale for the patient (unless the shop turned it off), and
 * whenever something required is missing.
 */
const props = defineProps<{
    open: boolean;
    /** Why the till opened it (payment asked for a prescriber); null when opened with F7. */
    message: string | null;
    /** What still stops payment (prescriber, patient); null = nothing. */
    blocking: string | null;
    /** A controlled line with the register on: the patient and their phone, as before. */
    needsPatient: boolean;
    /** Show the patient (the shop asks for one, or the register needs one). */
    showPatient: boolean;
    /** The shop requires the patient on this sale (or the register does). */
    patientRequired: boolean;
    /** Opened from payment: the button continues to payment. */
    toPayment: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    continue: [];
    /** The cashier skipped the patient (allowed only when not required). */
    skip: [];
}>();

const cart = usePosCart();
const { state } = cart;
const picker = ref<InstanceType<typeof PatientPicker> | null>(null);
const prescriber = ref<HTMLInputElement | null>(null);

watch(
    () => props.open,
    async (open) => {
        if (!open) {
            return;
        }

        await nextTick();

        if (props.showPatient && state.patient === null) {
            picker.value?.focus();
        } else {
            prescriber.value?.focus();
            prescriber.value?.select();
        }
    },
);

function setPatient(patient: SalePatient | null): void {
    cart.setPatient(patient);
}

function done(): void {
    if (props.toPayment && props.blocking !== null) {
        return;
    }

    emit('continue');
}

/** Enter in the patient box with nothing left to pick: on to the prescriber, or continue. */
function patientDone(): void {
    if (cart.hasRxLines.value && prescriber.value) {
        prescriber.value.focus();
        prescriber.value.select();

        return;
    }

    done();
}

const canSkip = () =>
    props.showPatient && !props.patientRequired && state.patient === null;

function skip(): void {
    if (canSkip()) {
        emit('skip');
    }
}

/** Esc skips the patient when that is allowed on the way to payment; else back to the sale. */
function onEscape(event: KeyboardEvent): void {
    if (props.toPayment && canSkip() && props.blocking === null) {
        event.preventDefault();
        skip();
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            class="max-h-[95vh] gap-4 overflow-y-auto sm:max-w-lg"
            data-test="prescription-dialog"
            @escape-key-down="onEscape"
        >
            <DialogHeader>
                <DialogTitle>{{
                    showPatient ? 'Patient & prescription' : 'Prescription'
                }}</DialogTitle>
                <DialogDescription>
                    <template v-if="showPatient"
                        >Find the patient by name, or add them with their age.
                    </template>
                    <template v-if="cart.hasRxLines.value"
                        >The prescription applies to every prescription-only or
                        controlled line; a line's own prescriber is set on the
                        line.</template
                    >
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
                <PatientPicker
                    v-if="showPatient"
                    ref="picker"
                    :model-value="state.patient"
                    :required="patientRequired"
                    @update:model-value="setPatient"
                    @submit="patientDone"
                />
                <label
                    v-if="needsPatient"
                    class="grid gap-1 text-sm font-medium"
                >
                    Patient phone (optional)
                    <input
                        v-model="state.customerPhone"
                        type="tel"
                        maxlength="50"
                        autocomplete="off"
                        class="h-12 w-full min-w-0 rounded-lg border bg-background px-3 text-base font-normal"
                        data-test="patient-phone"
                    />
                </label>

                <template v-if="cart.hasRxLines.value">
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

                    <ul class="grid gap-1 rounded-lg border p-2 text-sm">
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
                                        cart.prescriberFor(line) ??
                                        'no prescriber'
                                    }}</span
                                >
                            </li>
                        </template>
                    </ul>
                </template>

                <DialogFooter class="flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        class="h-12"
                        @click="emit('update:open', false)"
                        >Back to the sale</Button
                    >
                    <Button
                        v-if="
                            showPatient && toPayment && state.patient === null
                        "
                        type="button"
                        variant="outline"
                        class="h-12"
                        :disabled="!canSkip()"
                        :title="
                            patientRequired
                                ? 'This sale needs the patient'
                                : undefined
                        "
                        data-test="patient-skip"
                        @click="skip"
                        >Skip patient
                        <kbd class="text-xs opacity-80">Esc</kbd></Button
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
