<?php

namespace App\Policies;

use App\Models\User;

/** Suppliers: read by whoever orders or receives goods, or reads payables; changed with purchasing.manage. Deactivated, never deleted. */
class SupplierPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'purchasing.manage';

    protected ?string $managePermission = 'purchasing.manage';

    public function viewAny(User $user): bool
    {
        return $user->can('purchasing.manage') || $user->can('purchasing.receive') || $user->can('payables.view');
    }
}
