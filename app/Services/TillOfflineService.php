<?php

namespace App\Services;

use App\Models\Device;
use App\Models\User;
use App\Observers\AuditObserver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;

/**
 * Which tills may sell offline, and which browser is each till
 * (docs/deploy/TILLS.md). The owner approves a till (`devices.offline_allowed`,
 * Administration → Tills, or Set up offline sales at the till); an approved
 * till then prepares itself: whoever is signed in on it, a browser that has
 * no token claims the till's one token (claim()). A till that was never
 * approved, or was revoked, never becomes offline-capable by itself.
 *
 * Revoking turns the approval off as well as deleting the tokens: otherwise
 * the browser would register itself again at once.
 */
class TillOfflineService
{
    public const REGISTERED_EVENT = 'till.offline_registered';

    public const REVOKED_EVENT = 'till.offline_revoked';

    public const RESET_EVENT = 'till.offline_reset';

    public function __construct(private readonly AuditObserver $audit) {}

    /** The owner, at the till, in person: this browser is the till (Set up offline sales). */
    public function register(Device $till, User $owner, ?string $agent): NewAccessToken
    {
        return DB::transaction(function () use ($till, $owner, $agent): NewAccessToken {
            $till->update(['offline_allowed' => true]);

            return $this->issue($till, "{$owner->name} · ".Str::limit((string) $agent, 150), $owner);
        });
    }

    /**
     * A browser on an approved till takes the till's token. One browser for
     * a till: a second one is refused until the owner resets the till, so a
     * cashier's sign-in somewhere else does not get a copy of the till. The
     * lock makes two browsers claiming at once take turns. Refuses with 403
     * (not approved) or 409 (the till has its browser).
     */
    public function claim(Device $till, User $user, ?string $agent): NewAccessToken
    {
        return DB::transaction(function () use ($till, $user, $agent): NewAccessToken {
            $locked = Device::query()->whereKey($till->getKey())->lockForUpdate()->firstOrFail();

            abort_unless($locked->offline_allowed, 403, 'This till is not approved for offline sales.');
            abort_if($locked->tokens()->exists(), 409, 'This till is already set up on another browser.');

            return $this->issue($locked, "auto · {$user->name} · ".Str::limit((string) $agent, 150), $user);
        });
    }

    /** Stops every browser registered as this till, and the till preparing itself again. */
    public function revoke(Device $till, User $owner): int
    {
        return DB::transaction(function () use ($till, $owner): int {
            $revoked = $till->revokeSyncTokens();
            $till->update(['offline_allowed' => false]);
            $this->audit->record(self::REVOKED_EVENT, $till, [], ['tokens_revoked' => $revoked], (int) $owner->getKey());

            return $revoked;
        });
    }

    /**
     * For a till whose browser lost its token (Chrome was reset, the PC
     * replaced): frees the till's token and keeps it approved, so the till
     * registers itself the next time it loads.
     */
    public function reset(Device $till, User $owner): int
    {
        return DB::transaction(function () use ($till, $owner): int {
            $revoked = $till->revokeSyncTokens();
            $till->update(['offline_allowed' => true]);
            $this->audit->record(self::RESET_EVENT, $till, [], ['tokens_revoked' => $revoked], (int) $owner->getKey());

            return $revoked;
        });
    }

    private function issue(Device $till, string $name, User $user): NewAccessToken
    {
        $token = $till->issueSyncToken($name);
        $this->audit->record(self::REGISTERED_EVENT, $till, [], ['browser' => $name], (int) $user->getKey());

        return $token;
    }
}
