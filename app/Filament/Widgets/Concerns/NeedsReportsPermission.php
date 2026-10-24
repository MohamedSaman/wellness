<?php

namespace App\Filament\Widgets\Concerns;

use App\Models\User;

/** Dashboard widgets show shop-wide sales and stock, so they need reports.view. */
trait NeedsReportsPermission
{
    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('reports.view');
    }
}
