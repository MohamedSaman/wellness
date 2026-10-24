<?php

namespace App\Policies;

use App\Models\User;

/**
 * Invoices owed to suppliers (payables): read with payables.view or
 * purchasing.manage. They are created by posting GRNs and entering opening
 * balances, never by hand; paying them and moving a due date needs
 * purchasing.manage (checked by SupplierPayablesService).
 */
class SupplierPayablePolicy extends PermissionPolicy
{
    protected string $viewPermission = 'payables.view';

    protected ?string $managePermission = 'purchasing.manage';

    public function viewAny(User $user): bool
    {
        return $user->can('payables.view') || $user->can('purchasing.manage');
    }

    public function create(User $user): bool
    {
        return false;
    }
}
