import { createInertiaApp, usePage } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { initializeIdleLogout } from '@/lib/idleLogout';

// The tab title ends with the shop's name (shared `name`, BrandingService::name).
const appName = (): string => usePage().props.name ?? '';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName()}` : appName()),
    layout: (name) => {
        switch (true) {
            // The checkout screen and receipts use their own full-screen layout.
            case name.startsWith('pos/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();

// This will sign out a user who has been idle for the shop's limit...
initializeIdleLogout();
