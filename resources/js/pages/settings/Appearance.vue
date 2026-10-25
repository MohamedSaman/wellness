<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import ThemePresetPicker from '@/components/ThemePresetPicker.vue';
import type { ThemePresetOption } from '@/components/ThemePresetPicker.vue';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Appearance settings',
                href: edit(),
            },
        ],
    },
});

// The shop's colour theme: only the owner gets it (AppearanceController).
defineProps<{
    theme: {
        current: string;
        presets: ThemePresetOption[];
        hasBrandColor: boolean;
    } | null;
}>();
</script>

<template>
    <Head title="Appearance settings" />

    <h1 class="sr-only">Appearance settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Appearance settings"
            description="Update the appearance settings for your account"
        />
        <AppearanceTabs />
    </div>

    <div v-if="theme" class="space-y-6">
        <Heading
            variant="small"
            title="Clinic colour theme"
            description="The colours of the till, these pages and the admin panel, for everyone in the shop"
        />
        <ThemePresetPicker
            :presets="theme.presets"
            :current="theme.current"
            :has-brand-color="theme.hasBrandColor"
        />
    </div>
</template>
