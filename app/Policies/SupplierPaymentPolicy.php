<?php

namespace App\Policies;

use App\Models\User;

/**
 * Payments to suppliers: recorded and reversed with purchasing.manage
 * (through SupplierService, audited), read with payables.view too. Never
 * edited or deleted.
 */
class SupplierPaymentPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'purchasing.manage';

    protected ?string $managePermission = 'purchasing.manage';

    public function viewAny(User $user): bool
    {
        return $user->can('payables.view') || $user->can('purchasing.manage');
    }
}
