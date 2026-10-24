<?php

namespace Database\Seeders\Concerns;

use App\Models\Shop;
use App\Models\User;
use App\Support\InstallProfile;

/**
 * Shared by the demo shop seeders (local development and tests only).
 */
trait SeedsDemoShop
{
    /**
     * The profile this demo belongs to, or null when db:seed runs under
     * another profile, so a pharmacy install never gets the moto-gear demo
     * and the other way round.
     */
    private function demoProfile(): ?InstallProfile
    {
        if (app()->isProduction()) {
            throw new \RuntimeException(class_basename(static::class).' refused to run in production.');
        }

        $profile = InstallProfile::current();

        return $profile->seeds(static::class) ? $profile : null;
    }

    private function user(Shop $shop, string $name, string $email, string $role): User
    {
        $user = new User;
        $user->forceFill([
            'shop_id' => $shop->getKey(),
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'email_verified_at' => now(),
        ])->save();

        $user->assignRole($role);

        return $user;
    }
}
