<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Models\Shop;
use App\Support\CurrentShop;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The offline-sync endpoints are called by a till, not a person: the request
 * must carry a till's Sanctum token with the `pos:sync` ability. The till's
 * shop becomes the current shop, so everything after this is tenant-scoped
 * exactly as it is for a signed-in user. Browser sessions are never accepted.
 */
class AuthenticateTill
{
    public function __construct(private readonly CurrentShop $currentShop) {}

    public function handle(Request $request, Closure $next): Response
    {
        $device = Auth::guard('sanctum')->user();

        abort_unless(
            $device instanceof Device && $device->tokenCan(Device::SYNC_ABILITY),
            401,
            'This till is not registered for offline sales, or its access was revoked.',
        );

        $shop = Shop::query()->whereKey($device->shop_id)->first();

        abort_unless(
            $shop !== null && $shop->is_active && $device->is_active,
            403,
            'This till has been disabled.',
        );

        $request->attributes->set('device', $device);
        $device->forceFill(['last_seen_at' => now(), ...self::report($request)])->saveQuietly();

        return $this->currentShop->runAs($shop, fn (): Response => $next($request));
    }

    /**
     * What the till reports with every sync request (lib/offline/sync.ts), for
     * System health: X-Till-Pending (offline sales it holds to upload) and
     * X-Till-Time (its clock). Nothing when it sends neither (an older till).
     *
     * @return array<string, mixed>
     */
    private static function report(Request $request): array
    {
        $pending = $request->header('X-Till-Pending');
        $time = $request->header('X-Till-Time');

        if (! is_string($pending) || ! ctype_digit($pending)) {
            return [];
        }

        try {
            $offset = is_string($time) && $time !== '' ? Carbon::parse($time)->getTimestamp() - now()->getTimestamp() : null;
        } catch (\Throwable) {
            $offset = null;
        }

        return [
            'pending_uploads' => min((int) $pending, 4_000_000_000),
            'clock_offset_seconds' => $offset === null ? null : max(-2_000_000_000, min(2_000_000_000, $offset)),
            'reported_at' => now(),
        ];
    }
}
