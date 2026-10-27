<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

/*
 * Signing in and the other auth pages: the shop on one side (its logo, or
 * its mark when it has none, its name and tagline on the sidebar's colour),
 * the form on a card on the other. Side by side from 1024 px, the shop on
 * top below that. Every colour is the theme's, so it follows the preset and
 * dark mode; nothing loads from outside (the CSP is unchanged).
 */
defineProps<{
    title?: string;
    description?: string;
}>();

const brand = usePage().props.brand;
</script>

<template>
    <div class="grid min-h-svh bg-background lg:grid-cols-2">
        <aside
            class="relative flex flex-col items-center justify-center gap-3 overflow-hidden bg-sidebar px-6 py-8 text-center text-sidebar-foreground lg:gap-5 lg:py-12"
            data-test="brand-panel"
        >
            <!-- A faint pattern of medical line icons (a clinic only). -->
            <svg
                v-if="brand.clinic"
                class="pointer-events-none absolute inset-0 size-full opacity-[0.05]"
                aria-hidden="true"
            >
                <defs>
                    <pattern
                        id="auth-brand-pattern"
                        width="120"
                        height="120"
                        patternUnits="userSpaceOnUse"
                    >
                        <g
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M20 10h8v10h10v8H28v10h-8V28H10v-8h10z" />
                            <g transform="rotate(-35 80 25)">
                                <rect
                                    x="62"
                                    y="18"
                                    width="36"
                                    height="14"
                                    rx="7"
                                />
                                <path d="M80 18v14" />
                            </g>
                            <path d="M6 88h18l6-12 8 24 6-12h14" />
                            <path d="M96 74v16M88 82h16" />
                        </g>
                    </pattern>
                </defs>
                <rect
                    width="100%"
                    height="100%"
                    fill="url(#auth-brand-pattern)"
                />
            </svg>

            <img
                v-if="brand.logoUrl"
                :src="brand.logoUrl"
                :alt="brand.name"
                class="relative max-h-20 w-auto max-w-[70%] object-contain lg:max-h-40"
                data-test="brand-logo"
            />
            <span
                v-else
                class="relative flex size-14 items-center justify-center rounded-2xl bg-white/10 text-sidebar-indicator lg:size-24"
                data-test="brand-mark"
            >
                <AppLogoIcon class="size-8 lg:size-14" />
            </span>

            <p
                class="relative max-w-full text-2xl font-semibold tracking-tight break-words lg:text-4xl"
                data-test="brand-name"
            >
                {{ brand.name }}
            </p>
            <p
                class="relative text-sm text-sidebar-muted lg:text-base"
                data-test="brand-tagline"
            >
                {{ brand.tagline }}
            </p>
        </aside>

        <main
            class="flex flex-col items-center justify-center gap-6 px-4 py-8 sm:px-6 lg:py-12"
        >
            <div
                class="w-full max-w-sm rounded-xl border bg-card p-6 text-card-foreground shadow-sm sm:p-8"
                data-slot="card"
                data-test="auth-card"
            >
                <div class="mb-6 space-y-1.5">
                    <h1 class="text-xl font-semibold">{{ title }}</h1>
                    <p class="text-sm text-muted-foreground">
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>

            <footer class="text-xs text-muted-foreground">
                Powered by
                <a
                    href="https://webxkey.lk"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="font-medium underline-offset-4 hover:underline"
                    data-test="powered-by"
                    >Webxkey</a
                >
            </footer>
        </main>
    </div>
</template>
