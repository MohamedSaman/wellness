<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Read-only. sales.view means own sales, sales.view_all means every sale. */
class SalePolicy extends PermissionPolicy
{
    protected ?string $managePermission = null;

    public function viewAny(User $user): bool
    {
        return $user->can('sales.view_all') || $user->can('sales.view');
    }

    public function view(User $user, Model $record): bool
    {
        return $user->can('sales.view_all')
            || ($user->can('sales.view') && $record instanceof Sale && (int) $record->user_id === (int) $user->getKey());
    }
}
