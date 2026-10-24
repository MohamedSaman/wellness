<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Supplier returns: purchasing.manage. Posted when saved and never edited (the credit note number is added through its own action). */
class SupplierReturnPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'purchasing.manage';

    protected ?string $managePermission = 'purchasing.manage';

    public function update(User $user, Model $record): bool
    {
        return false;
    }
}
