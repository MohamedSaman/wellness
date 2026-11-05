<script setup lang="ts">
import {
    CircleAlert,
    CircleCheck,
    CloudOff,
    KeyRound,
    LoaderCircle,
    RefreshCw,
    Wifi,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useOfflinePos } from '@/composables/useOfflinePos';
import { ApiError } from '@/lib/http';

/**
 * Connection badge, whether this till can sell offline, "Refresh now" (it
 * refreshes by itself; this is the fallback) and, for the owner, approving a
 * till that is not ready for offline sales here and now.
 */
const props = defineProps<{ canRegister: boolean }>();

const { state, isOffline, readiness, syncNow, register } = useOfflinePos();
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

const readinessLabel = computed(
    () =>
        ({
            ready: 'Ready for offline',
            preparing: 'Preparing offline...',
            waiting: 'Not ready for offline - reconnect',
            unavailable: 'Offline not available on this till - ask the owner',
        })[readiness.value],
);

/** Ready goes without saying once the till is selling offline: the badge says Offline. */
const showReadiness = computed(
    () => !(isOffline.value && readiness.value === 'ready'),
);

const needsRegistration = computed(
    () => !state.registered || state.denied || state.accessError !== null,
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
            v-if="showReadiness"
            class="inline-flex items-center gap-1.5 text-sm font-medium"
            :class="
                readiness === 'ready'
                    ? 'text-emerald-700 dark:text-emerald-400'
                    : readiness === 'preparing'
                      ? 'text-muted-foreground'
                      : 'text-amber-700 dark:text-amber-400'
            "
            :title="state.accessError ?? state.lastSyncError ?? undefined"
            role="status"
            aria-live="polite"
            :data-state="readiness"
            data-test="offline-readiness"
        >
            <CircleCheck v-if="readiness === 'ready'" class="size-4" />
            <LoaderCircle
                v-else-if="readiness === 'preparing'"
                class="size-4 animate-spin"
            />
            <CircleAlert v-else class="size-4" />
            {{ readinessLabel }}
        </span>

        <span
            v-if="state.rejected > 0"
            class="rounded-full bg-destructive/10 px-3 py-1 text-sm font-semibold text-destructive"
            :title="state.lastSyncError ?? undefined"
            data-test="rejected-sales"
        >
            {{ sales(state.rejected) }} refused by the server – press Refresh
            now to retry
        </span>

        <template v-if="needsRegistration">
            <Button
                v-if="props.canRegister && readiness === 'unavailable'"
                variant="outline"
                class="h-11"
                :disabled="registering"
                data-test="register-till"
                @click="registerTill"
            >
                <KeyRound class="size-4" />
                {{ registering ? 'Registering…' : 'Set up offline sales' }}
            </Button>
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
            {{ state.syncing ? 'Refreshing…' : 'Refresh now' }}
        </Button>
    </div>
</template>
