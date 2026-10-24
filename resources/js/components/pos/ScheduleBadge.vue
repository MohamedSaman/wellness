<script setup lang="ts">
import { scheduleLabels } from '@/lib/pharmacyDisplay';
import type { DrugSchedule } from '@/types/pos';

/** A medicine's schedule at the till: OTC, Rx (prescription only) or Controlled. */
defineProps<{ schedule: DrugSchedule }>();

const classes: Record<DrugSchedule, string> = {
    otc: 'border-border text-muted-foreground',
    prescription_only:
        'border-transparent bg-sky-600/15 text-sky-700 dark:text-sky-300',
    controlled:
        'border-transparent bg-destructive/15 text-destructive dark:text-red-300',
};
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center rounded-full border px-2 py-0.5 text-xs font-bold whitespace-nowrap"
        :class="classes[schedule]"
        :title="
            schedule === 'otc'
                ? 'Over the counter'
                : schedule === 'controlled'
                  ? 'Controlled drug: needs a prescription'
                  : 'Prescription only'
        "
        data-test="schedule-badge"
        >{{ scheduleLabels[schedule] }}</span
    >
</template>
