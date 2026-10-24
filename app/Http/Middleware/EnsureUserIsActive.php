<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Logs out users the owner has deactivated, on their very next request. */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // null = column default (active) not yet loaded on this instance.
        if ($user !== null && ($user->getAttribute('is_active') ?? true) == false) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Your account has been disabled. Please contact the shop owner.',
            ]);
        }

        return $next($request);
    }
}
