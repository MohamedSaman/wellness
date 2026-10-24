<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { MapPin, Store } from '@lucide/vue';
import TillController from '@/actions/App/Http/Controllers/Pos/TillController';
import type { PosTillOption } from '@/types/pos';

defineProps<{ tills: PosTillOption[] }>();

function choose(till: PosTillOption): void {
    router.post(TillController.store.url(), { device: till.uuid });
}
</script>

<template>
    <div
        class="mx-auto flex max-w-3xl flex-col gap-6 p-6"
        data-test="till-picker"
    >
        <div>
            <h1 class="text-2xl font-bold">Choose a till</h1>
            <p class="text-muted-foreground">This browser will remember it.</p>
        </div>

        <p
            v-if="tills.length === 0"
            class="rounded-xl border p-6 text-muted-foreground"
        >
            No tills are set up for this shop. Ask the owner to register one.
        </p>

        <div class="grid gap-3 sm:grid-cols-2">
            <button
                v-for="till in tills"
                :key="till.uuid"
                type="button"
                class="flex min-h-24 items-center gap-4 rounded-xl border bg-card p-4 text-left transition hover:bg-accent active:scale-[0.99]"
                data-test="till-option"
                @click="choose(till)"
            >
                <Store class="size-8 text-muted-foreground" />
                <span>
                    <span class="block text-2xl font-bold">{{
                        till.code
                    }}</span>
                    <span class="block text-muted-foreground">{{
                        till.name
                    }}</span>
                    <span
                        v-if="till.location"
                        class="flex items-center gap-1 text-sm font-medium"
                        data-test="till-location"
                        ><MapPin class="size-3.5" /> {{ till.location }}</span
                    >
                    <span
                        class="text-sm"
                        :class="
                            till.shiftOpen
                                ? 'text-emerald-600'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ till.shiftOpen ? 'Shift open' : 'No shift open' }}
                    </span>
                </span>
            </button>
        </div>
    </div>
</template>
