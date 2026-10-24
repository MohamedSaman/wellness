<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import ShiftController from '@/actions/App/Http/Controllers/Pos/ShiftController';
import { Button } from '@/components/ui/button';
import { ApiError, postJson } from '@/lib/http';
import type { PosShop, PosTill } from '@/types/pos';

const props = defineProps<{ till: PosTill; shop: PosShop }>();
const emit = defineEmits<{ skip: [] }>();

const openingCash = ref('');
const error = ref<string | null>(null);
const busy = ref(false);

async function open(): Promise<void> {
    busy.value = true;
    error.value = null;

    try {
        await postJson(ShiftController.open.url(), {
            device: props.till.uuid,
            opening_cash: openingCash.value || '0',
        });
        router.reload({ only: ['shift', 'tills'] });
    } catch (caught) {
        error.value =
            caught instanceof ApiError
                ? caught.message
                : 'Could not open the shift.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div
        class="mx-auto flex max-w-md flex-col gap-5 p-6"
        data-test="shift-open"
    >
        <div>
            <h1 class="text-2xl font-bold">Open a shift on {{ till.code }}</h1>
            <p class="text-muted-foreground">
                Count the cash in the drawer and enter it as the opening float.
            </p>
        </div>

        <label class="grid gap-2">
            <span class="font-medium">Opening cash ({{ shop.currency }})</span>
            <input
                v-model="openingCash"
                type="text"
                inputmode="decimal"
                placeholder="0.00"
                autofocus
                class="h-16 w-full min-w-0 rounded-xl border bg-background px-4 text-right text-3xl font-bold tabular-nums"
                data-test="opening-cash"
                @keydown.enter.prevent="open"
            />
        </label>

        <p
            v-if="error"
            class="rounded-lg bg-destructive/10 p-3 text-sm font-medium text-destructive"
            role="alert"
        >
            {{ error }}
        </p>

        <Button
            class="h-14 text-lg"
            :disabled="busy"
            data-test="open-shift"
            @click="open"
            >Open shift</Button
        >
        <Button
            v-if="!shop.requireOpenShift"
            variant="ghost"
            class="h-12"
            @click="emit('skip')"
            >Sell without a shift</Button
        >
    </div>
</template>
