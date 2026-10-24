<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Users with a role in pos.security.two_factor_roles (the owner) cannot use
 * the app until they have confirmed two-factor authentication: every page
 * sends them to Settings → Security, where Fortify sets it up. For other
 * users it stays optional. Only while pos.security.require_two_factor is on
 * (production by default; docs/deploy/CLOUD.md).
 */
class RequireTwoFactor
{
    /** What a user without 2FA may still reach: setting it up, confirming the password, signing out. */
    private const ALLOWED_ROUTES = [
        'security.edit',
        'user-password.update',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'two-factor.*',
        'passkey.*',
        'logout',
        'filament.admin.auth.logout',
        'appearance.edit',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! self::isRequiredFor($user) || $user->two_factor_confirmed_at !== null || $request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        $message = 'Your account needs two-factor authentication. Turn it on to continue.';

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 403);
        }

        return redirect()->route('security.edit')->with('status', $message);
    }

    /** Whether this user must have two-factor authentication (whatever they have now). */
    public static function isRequiredFor(User $user): bool
    {
        return (bool) config('pos.security.require_two_factor')
            && $user->hasAnyRole((array) config('pos.security.two_factor_roles', []));
    }
}
