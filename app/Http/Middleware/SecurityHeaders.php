<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Camera allowed for this site only (scanning barcodes with a tablet camera).
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure() && app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (config('pos.security.csp') && ! Vite::isRunningHot() && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', self::contentSecurityPolicy());
        }

        return $response;
    }

    /**
     * Everything from this site only. Scripts and styles may be inline because
     * Livewire, Alpine (Filament) and the till's theme script need it; what
     * the policy still stops is loading or sending anything to another site,
     * framing the app, plugins and changing the base URL. Fonts from Bunny
     * Fonts and rsms.me (the starter kit's), images also as data: and blob:
     * (barcodes, the logo preview), the service worker and printing iframes
     * from blob:, and QZ Tray on the till PC itself (lib/receiptPrinter.ts,
     * optional: the cloud tills print through Chrome, docs/deploy/TILLS.md).
     */
    public static function contentSecurityPolicy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://rsms.me",
            "font-src 'self' data: https://fonts.bunny.net https://rsms.me",
            "img-src 'self' data: blob:",
            "connect-src 'self' wss://localhost:8181 ws://localhost:8182",
            "worker-src 'self' blob:",
            "frame-src 'self' blob:",
            "manifest-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }
}
