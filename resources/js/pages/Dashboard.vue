<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ShoppingCart } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { dashboard } from '@/routes';
import { index as pos } from '@/routes/pos';

// For anyone who doesn't use the admin panel (DashboardController): a greeting and the way to the till.
defineProps<{
    firstName: string;
    greeting: string;
    canUsePos: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="p-4 md:p-6">
        <Card class="max-w-2xl">
            <CardContent class="space-y-4">
                <p
                    class="text-xs font-semibold tracking-wider text-primary-text uppercase"
                >
                    {{ greeting }}
                </p>
                <h1 class="text-2xl font-semibold" data-test="welcome-heading">
                    Welcome, {{ firstName }}.
                </h1>
                <p v-if="canUsePos" class="text-muted-foreground">
                    Sales and OPD bills are rung up at the till.
                </p>
                <p v-else class="text-muted-foreground">
                    Your account has nothing to open here yet. Ask the owner for
                    access.
                </p>
                <Button
                    v-if="canUsePos"
                    as="a"
                    :href="pos().url"
                    size="lg"
                    class="h-12 px-6 text-base"
                    data-test="open-pos-button"
                >
                    <ShoppingCart class="size-5" aria-hidden="true" />
                    Open POS
                </Button>
            </CardContent>
        </Card>
    </div>
</template>
