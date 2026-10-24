import { router } from '@inertiajs/vue3';
import { logout } from '@/routes';

/**
 * Idle sign-out on every Inertia page (Administration → Security): the till
 * (till_idle_minutes) and the rest of the site (admin_idle_minutes). Each
 * page says its limit in the shared `idleTimeout` prop (seconds, 0 = none).
 * Any key, click, scroll or touch in any of the user's tabs is activity
 * (shared through localStorage). Once there has been none for the limit, the
 * user is signed out, but only while the browser is online: a till that is
 * offline keeps its cashier signed in, so billing never stops during an
 * outage, and signs out when the connection is back if still idle. The
 * server signs an idle session out too (LogOutIdleUsers).
 */
const KEY = 'pos.lastActivity';
const CHECK_EVERY_MS = 15_000;

let limitMs = 0;
let lastLocal = Date.now();
let signingOut = false;

function touch(): void {
    lastLocal = Date.now();

    try {
        localStorage.setItem(KEY, String(lastLocal));
    } catch {
        // Private mode or storage blocked: this tab's own activity still counts.
    }
}

function lastActivity(): number {
    let shared = 0;

    try {
        shared = Number(localStorage.getItem(KEY)) || 0;
    } catch {
        shared = 0;
    }

    return Math.max(lastLocal, shared);
}

async function signOut(): Promise<void> {
    signingOut = true;
    // The cached checkout page holds this cashier's details; drop it, as signing out by hand does.
    await window.caches?.delete('pos-pages').catch(() => false);
    router.post(logout().url, {}, { onFinish: () => (signingOut = false) });
}

export function initializeIdleLogout(): void {
    if (typeof window === 'undefined') {
        return;
    }

    for (const type of ['keydown', 'pointerdown', 'wheel', 'touchstart']) {
        document.addEventListener(type, touch, {
            passive: true,
            capture: true,
        });
    }

    router.on('navigate', (event) => {
        const seconds = Number(event.detail.page.props.idleTimeout ?? 0);
        limitMs = Number.isFinite(seconds) && seconds > 0 ? seconds * 1000 : 0;
        touch();
    });

    setInterval(() => {
        if (
            limitMs > 0 &&
            !signingOut &&
            navigator.onLine &&
            Date.now() - lastActivity() > limitMs
        ) {
            void signOut();
        }
    }, CHECK_EVERY_MS);
}
