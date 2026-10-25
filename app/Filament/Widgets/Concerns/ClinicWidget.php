<?php

namespace App\Filament\Widgets\Concerns;

use App\Models\User;
use App\Services\ClinicDashboardService;
use App\Support\CurrentShop;

/**
 * What the clinic dashboard's widgets (app/Filament/Widgets/Clinic) share:
 * they show on a pharmacy shop only, each behind the permissions the rest of
 * the panel uses for the same figures (reports.view for sales, stock.view
 * for stock, cost.view for anything at cost), and read their figures from
 * ClinicDashboardService. They are not discovered: Dashboard lays them out.
 */
trait ClinicWidget
{
    /** The pharmacy module is on and the signed-in user has every one of the permissions. */
    protected static function clinicUserCan(string ...$permissions): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! (app(CurrentShop::class)->get()?->hasModule('pharmacy') ?? false)) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (! $user->can($permission)) {
                return false;
            }
        }

        return true;
    }

    protected static function userCan(string $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can($permission);
    }

    protected function figures(): ClinicDashboardService
    {
        return app(ClinicDashboardService::class);
    }
}
