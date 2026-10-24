/// <reference lib="webworker" />
import { clientsClaim } from 'workbox-core';
import { ExpirationPlugin } from 'workbox-expiration';
import { cleanupOutdatedCaches, precacheAndRoute } from 'workbox-precaching';
import { registerRoute } from 'workbox-routing';
import { CacheFirst, NetworkFirst } from 'workbox-strategies';

/**
 * Service worker for the checkout screen (built by vite-plugin-pwa into
 * public/sw.js). It keeps the POS app shell available without internet:
 *  - scripts, styles and fonts from the Vite build are precached;
 *  - the /pos page itself is network-first, falling back to the last copy
 *    that loaded successfully;
 *  - nothing else is cached: the admin panel, reports and every API call
 *    always go to the server.
 */
declare const self: ServiceWorkerGlobalScope;

void self.skipWaiting();
clientsClaim();
cleanupOutdatedCaches();
precacheAndRoute(self.__WB_MANIFEST);

registerRoute(
    ({ request, url }) =>
        request.mode === 'navigate' &&
        url.origin === self.location.origin &&
        url.pathname === '/pos',
    new NetworkFirst({
        cacheName: 'pos-pages',
        networkTimeoutSeconds: 5,
        plugins: [
            {
                // Never store a redirect to the login page (an expired session) as the checkout page.
                cacheWillUpdate: async ({ response }) =>
                    response.status === 200 &&
                    !response.redirected &&
                    new URL(response.url).pathname === '/pos'
                        ? response
                        : null,
            },
        ],
    }),
);

// The shop logo on receipts.
registerRoute(
    ({ url }) =>
        url.origin === self.location.origin &&
        url.pathname.startsWith('/storage/'),
    new CacheFirst({
        cacheName: 'pos-images',
        plugins: [new ExpirationPlugin({ maxEntries: 20 })],
    }),
);
