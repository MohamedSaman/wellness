<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/composables/useAppearance';
import { update } from '@/routes/appearance/theme';

/*
 * Settings → Appearance → Clinic colour theme (the owner only). Picking a
 * preset previews it on this page straight away, with the same rules the
 * server prints (ThemeService::appCss); Save makes it the shop's theme for
 * the till, these pages and the admin panel. Leaving without saving puts
 * the saved theme back.
 */

type Colors = Record<string, string>;

export type ThemePresetOption = {
    key: string;
    label: string;
    css: string;
    colors: { light: Colors; dark: Colors };
};

const props = defineProps<{
    presets: ThemePresetOption[];
    current: string;
    hasBrandColor: boolean;
}>();

const { resolvedAppearance } = useAppearance();

const saved = ref(props.current);
const selected = ref(props.current);
const saving = ref(false);
const brandCleared = ref(false);

const PREVIEW_ID = 'pos-theme-preview';

const option = (key: string): ThemePresetOption | undefined =>
    props.presets.find((preset) => preset.key === key);

const selectedOption = computed(() => option(selected.value));
const mode = computed(() =>
    resolvedAppearance.value === 'dark' ? 'dark' : 'light',
);
const preview = computed<Colors>(
    () => selectedOption.value?.colors[mode.value] ?? {},
);

/* The brand colour's own block (BrandingService::tillStyle) would cover the preview's primary. */
const brandStyles = (): HTMLStyleElement[] =>
    Array.from(document.head.querySelectorAll('style')).filter((style) =>
        (style.textContent ?? '').startsWith('html:root{--primary'),
    );

function apply(key: string): void {
    const preset = option(key);

    if (!preset) {
        return;
    }

    let style = document.getElementById(PREVIEW_ID);

    if (!style) {
        style = document.createElement('style');
        style.id = PREVIEW_ID;
        document.head.appendChild(style);
    }

    style.textContent = preset.css;

    if (preset.css === '') {
        delete document.documentElement.dataset.posTheme;
    } else {
        document.documentElement.dataset.posTheme = preset.key;
    }

    const hideBrand = key !== saved.value || brandCleared.value;

    for (const brand of brandStyles()) {
        brand.media = hideBrand ? 'not all' : '';
    }
}

watch(selected, (key) => apply(key));

onMounted(() => apply(selected.value));

onBeforeUnmount(() => {
    if (selected.value !== saved.value) {
        selected.value = saved.value;
        apply(saved.value);
    }
});

function save(): void {
    saving.value = true;

    router.patch(
        update.url(),
        { preset: selected.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                saved.value = selected.value;
                brandCleared.value = true;
                apply(selected.value);
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="space-y-4" data-test="theme-preset-picker">
        <div
            class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
            role="radiogroup"
            aria-label="Clinic colour theme"
        >
            <button
                v-for="preset in presets"
                :key="preset.key"
                type="button"
                role="radio"
                :aria-checked="selected === preset.key"
                :data-test="`theme-${preset.key}`"
                class="flex min-h-14 items-center gap-3 rounded-lg border p-3 text-left transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="
                    selected === preset.key
                        ? 'border-primary ring-1 ring-primary'
                        : 'border-border'
                "
                @click="selected = preset.key"
            >
                <span class="flex shrink-0 -space-x-1.5" aria-hidden="true">
                    <span
                        v-for="token in [
                            'sidebar',
                            'primary',
                            'background',
                            'danger',
                        ]"
                        :key="token"
                        class="size-5 rounded-full border border-black/10 dark:border-white/20"
                        :style="{
                            backgroundColor: preset.colors[mode][token],
                        }"
                    />
                </span>
                <span class="min-w-0 flex-1 truncate text-sm font-medium">
                    {{ preset.label }}
                </span>
                <Check
                    v-if="selected === preset.key"
                    class="size-4 shrink-0 text-primary"
                    aria-hidden="true"
                />
            </button>
        </div>

        <!-- The chosen theme in miniature: sidebar, a card, a button and an alert. -->
        <div
            class="flex overflow-hidden rounded-lg border"
            :style="{
                backgroundColor: preview.background,
                borderColor: preview.border,
            }"
            data-test="theme-preview"
            aria-hidden="true"
        >
            <div
                class="flex w-28 shrink-0 flex-col gap-1.5 p-3"
                :style="{ backgroundColor: preview.sidebar }"
            >
                <span
                    class="rounded-md px-2 py-1 text-xs font-medium"
                    :style="{
                        backgroundColor: preview.sidebar_active,
                        color: preview.sidebar_active_fg,
                    }"
                    >Dashboard</span
                >
                <span
                    class="px-2 py-1 text-xs"
                    :style="{ color: preview.sidebar_fg }"
                    >Stock</span
                >
                <span
                    class="px-2 py-1 text-xs"
                    :style="{ color: preview.sidebar_fg }"
                    >Reports</span
                >
            </div>
            <div class="min-w-0 flex-1 p-3">
                <div
                    class="space-y-2 rounded-[14px] border p-3"
                    :style="{
                        backgroundColor: preview.card,
                        borderColor: preview.border,
                    }"
                >
                    <p
                        class="text-sm font-semibold"
                        :style="{ color: preview.foreground }"
                    >
                        Today's sales
                    </p>
                    <p class="text-xs" :style="{ color: preview.muted }">
                        Up on yesterday
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <span
                            class="rounded-md px-2.5 py-1 text-xs font-medium"
                            :style="{
                                backgroundColor: preview.primary,
                                color: preview.primary_fg,
                            }"
                            >Open POS</span
                        >
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                            :style="{
                                backgroundColor: preview.danger_soft,
                                color: preview.danger_text,
                            }"
                            >2 expiring</span
                        >
                    </div>
                </div>
            </div>
        </div>

        <p
            v-if="hasBrandColor && !brandCleared && selected !== saved"
            class="text-sm text-muted-foreground"
        >
            Saving a theme clears the custom primary colour set in Shop settings
            → Branding.
        </p>

        <div class="flex items-center gap-4">
            <Button
                :disabled="saving || selected === saved"
                data-test="save-theme-button"
                @click="save"
                >Save theme</Button
            >
            <span
                v-if="selected !== saved"
                class="text-sm text-muted-foreground"
                >Previewing {{ selectedOption?.label }}: not saved yet</span
            >
        </div>
    </div>
</template>
