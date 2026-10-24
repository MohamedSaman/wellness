<?php

namespace App\Support;

use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Remembers which till this browser sells on. The cookie only holds the
 * till's public UUID and is encrypted and signed by Laravel, but it is never
 * trusted by itself: the till is always looked up inside the user's shop.
 * A user limited to some locations (docs/PHARMACY.md §9, branch 8) can only
 * use the tills at those locations.
 */
class TillSelection
{
    public const COOKIE = 'pos_till';

    /** An active till of the current shop, or null (other shops' tills are invisible). */
    public static function find(?string $uuid): ?Device
    {
        if (blank($uuid)) {
            return null;
        }

        return Device::query()->where('uuid', $uuid)->where('is_active', true)->first();
    }

    /**
     * The remembered till, if the signed-in user may still use it. A till at a
     * location the user may not use is forgotten, so the till picker shows.
     */
    public static function remembered(Request $request): ?Device
    {
        $uuid = $request->cookie(self::COOKIE);
        $device = is_string($uuid) ? self::find($uuid) : null;
        $user = $request->user();

        if ($device !== null && $user instanceof User && ! self::usableBy($device, $user)) {
            self::forget();

            return null;
        }

        return $device;
    }

    /** The user may sell on this till: it is at a location they may use. */
    public static function usableBy(Device $device, User $user): bool
    {
        return $user->mayUseLocation((int) $device->location_id);
    }

    /** Why the user can't use this till, or null when they can. */
    public static function refusal(Device $device, User $user): ?string
    {
        if (self::usableBy($device, $user)) {
            return null;
        }

        $location = $device->location()->first();

        return "You can't use till {$device->code}: it is at {$location?->name}.";
    }

    public static function remember(Device $device): void
    {
        Cookie::queue(Cookie::make(self::COOKIE, $device->uuid, 60 * 24 * 365, httpOnly: true, sameSite: 'lax'));
    }

    public static function forget(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
    }
}
