<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Cross, ReceiptText } from '@lucide/vue';

// The shop's mark when it has no logo: a medical cross for a clinic, a receipt otherwise.
const clinic = usePage().props.brand?.clinic ?? false;
</script>

<template>
    <Cross v-if="clinic" aria-hidden="true" />
    <ReceiptText v-else aria-hidden="true" />
</template>
