<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * Password rules by what the account can do (decided 2026-10-22,
 * docs/deploy/CLOUD.md, Security). With strict passwords (production):
 *   - anyone who can open the admin panel (admin.access: the owner, an
 *     admin): at least 12 characters, not in a known breach (and two-factor
 *     authentication for the owner, RequireTwoFactor);
 *   - till-only users (cashiers): at least 10 characters, not in a known
 *     breach.
 * No rules on upper and lower case or symbols: length and the breach check
 * matter more, and a cashier types it at a busy counter. The breach check
 * asks Have I Been Pwned with the first 5 characters of the password's SHA-1
 * only. With pos.security.strict_passwords off (outside production by
 * default): Laravel's default (8 characters), so local development and the
 * tests are as before.
 */
final class PasswordRules
{
    public const ADMIN_MIN = 12;

    public const TILL_MIN = 10;

    public static function strict(): bool
    {
        return (bool) config('pos.security.strict_passwords');
    }

    public static function forAdmin(bool $admin): Password
    {
        if (! self::strict()) {
            return Password::min(8);
        }

        return Password::min($admin ? self::ADMIN_MIN : self::TILL_MIN)->uncompromised();
    }

    /** The rule for an existing user; null (not known) gets the stricter one. */
    public static function forUser(?User $user): Password
    {
        return self::forAdmin($user === null || $user->can('admin.access'));
    }

    /** The rule for a user who will have this role (the user form); none chosen yet: the stricter one. */
    public static function forRole(?string $role): Password
    {
        return self::forAdmin(self::roleIsAdmin($role));
    }

    /** What the rule asks for, as the user form says it. */
    public static function describe(bool $admin): string
    {
        if (! self::strict()) {
            return 'At least 8 characters.';
        }

        return 'At least '.($admin ? self::ADMIN_MIN : self::TILL_MIN).' characters, and not a password known from a data breach.'
            .($admin ? ' This role can open the admin panel.' : '');
    }

    public static function roleIsAdmin(?string $role): bool
    {
        if ($role === null || $role === '') {
            return true;
        }

        $model = Role::query()->where('name', $role)->where('guard_name', 'web')->first();

        return $model === null || $model->hasPermissionTo('admin.access', 'web');
    }
}
