<script setup lang="ts">
import { CloudOff, KeyRound, RefreshCw, Wifi } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useOfflinePos } from '@/composables/useOfflinePos';
import { ApiError } from '@/lib/http';

/** Connection badge, "Sync now" and (for the owner) registering this till for offline sales. */
const props = defineProps<{ canRegister: boolean }>();

const { state, isOffline, syncNow, register } = useOfflinePos();
const registering = ref(false);
const registerError = ref<string | null>(null);

function sales(count: number): string {
    return count === 1 ? '1 sale' : `${count} sales`;
}

const label = computed(() => {
    const waiting =
        state.pending > 0 ? ` – ${sales(state.pending)} waiting to sync` : '';

    return isOffline.value ? `Offline${waiting}` : `Online${waiting}`;
});

const needsRegistration = computed(
    () => !state.registered || state.accessError !== null,
);

async function registerTill(): Promise<void> {
    registering.value = true;
    registerError.value = null;

    try {
        await register();
    } catch (error) {
        registerError.value =
            error instanceof ApiError
                ? error.message
                : 'The till could not be registered.';
    } finally {
        registering.value = false;
    }
}
</script>

<template>
    <div
        v-if="state.enabled"
        class="flex flex-wrap items-center gap-2"
        data-test="offline-status"
    >
        <span
            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-semibold"
            :class="
                isOffline
                    ? 'bg-amber-500/15 text-amber-700 dark:text-amber-400'
                    : 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400'
            "
            role="status"
            aria-live="polite"
            data-test="connection-badge"
        >
            <CloudOff v-if="isOffline" class="size-4" />
            <Wifi v-else class="size-4" />
            {{ label }}
        </span>

        <span
            v-if="state.rejected > 0"
            class="rounded-full bg-destructive/10 px-3 py-1 text-sm font-semibold text-destructive"
            :title="state.lastSyncError ?? undefined"
            data-test="rejected-sales"
        >
            {{ sales(state.rejected) }} refused by the server – press Sync now
            to retry
        </span>

        <template v-if="needsRegistration">
            <span class="text-sm text-muted-foreground">
                {{
                    state.accessError ??
                    'Offline sales are not set up on this till.'
                }}
            </span>
            <Button
                v-if="props.canRegister"
                variant="outline"
                class="h-11"
                :disabled="registering"
                data-test="register-till"
                @click="registerTill"
            >
                <KeyRound class="size-4" />
                {{ registering ? 'Registering…' : 'Set up offline sales' }}
            </Button>
            <span v-else class="text-sm text-muted-foreground"
                >Ask the owner to set it up.</span
            >
            <span
                v-if="registerError"
                class="text-sm text-destructive"
                role="alert"
                >{{ registerError }}</span
            >
        </template>

        <Button
            v-else
            variant="outline"
            class="h-11"
            :disabled="state.syncing"
            data-test="sync-now"
            @click="syncNow"
        >
            <RefreshCw
                class="size-4"
                :class="{ 'animate-spin': state.syncing }"
            />
            {{ state.syncing ? 'Syncing…' : 'Sync now' }}
        </Button>
    </div>
</template>
