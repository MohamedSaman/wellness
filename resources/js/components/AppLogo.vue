<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const brand = usePage().props.brand;
</script>

<template>
    <div
        class="flex aspect-square size-8 items-center justify-center overflow-hidden rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
    >
        <img
            v-if="brand?.logoUrl"
            :src="brand.logoUrl"
            alt=""
            class="size-full bg-white object-contain p-0.5"
        />
        <AppLogoIcon v-else class="size-5" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold">{{
            brand?.name
        }}</span>
    </div>
</template>
