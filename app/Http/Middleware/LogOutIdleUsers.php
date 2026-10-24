<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out a user who has done nothing for the shop's idle limit: the till's
 * (till_idle_minutes) on /pos, the admin's (admin_idle_minutes) everywhere
 * else; 0 = never. The session's lifetime is not enough, because the
 * dashboard refreshes itself every minute and would keep it alive for ever:
 * requests that only refresh a page (Livewire polls, X-Background) do not
 * count as activity. The page itself reloads when the limit passes (the
 * admin panel's idle script and the till's useIdleLogout), and lands here.
 */
class LogOutIdleUsers
{
    public const SESSION_KEY = 'idle.last_active_at';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $request->hasSession()) {
            return $next($request);
        }

        $limit = self::limitSeconds($user, $request);
        $last = $request->session()->get(self::SESSION_KEY);
        $now = now()->getTimestamp();

        if ($limit > 0 && is_int($last) && $now - $last > $limit) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = 'You were signed out after '.intdiv($limit, 60).' minutes without activity.';

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $message], 401);
            }

            return redirect()->route('login')->with('status', $message);
        }

        if (! is_int($last) || ! self::isBackground($request)) {
            $request->session()->put(self::SESSION_KEY, $now);
        }

        return $next($request);
    }

    /** The idle limit for this request in seconds (0 = none): the till's on /pos, the admin's elsewhere. */
    public static function limitSeconds(User $user, Request $request): int
    {
        $shop = $user->shop_id === null ? null : Shop::query()->find($user->shop_id);

        if ($shop === null) {
            return 0;
        }

        $key = self::isTill($request) ? 'till_idle_minutes' : 'admin_idle_minutes';

        return max(0, (int) $shop->setting($key)) * 60;
    }

    public static function isTill(Request $request): bool
    {
        return $request->is('pos', 'pos/*');
    }

    /**
     * A request the page sent by itself: marked X-Background by our own code,
     * or a Livewire request that only refreshes (wire:poll: no updates, no
     * calls other than $refresh / $commit).
     */
    public static function isBackground(Request $request): bool
    {
        if ($request->header('X-Background') === '1') {
            return true;
        }

        if (! $request->hasHeader('X-Livewire')) {
            return false;
        }

        $components = $request->input('components');

        if (! is_array($components) || $components === []) {
            return false;
        }

        foreach ($components as $component) {
            if (! is_array($component) || ($component['updates'] ?? []) !== []) {
                return false;
            }

            foreach ((array) ($component['calls'] ?? []) as $call) {
                if (! in_array(is_array($call) ? ($call['method'] ?? null) : null, ['$refresh', '$commit'], true)) {
                    return false;
                }
            }
        }

        return true;
    }
}
