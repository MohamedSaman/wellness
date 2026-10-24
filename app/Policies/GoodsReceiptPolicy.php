<?php

namespace App\Policies;

use App\Models\GoodsReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Goods received notes: entered, edited and posted with purchasing.receive
 * (owner now; a pharmacist role later), read with either purchasing
 * permission. Only drafts are edited or deleted; a posted GRN is reversed
 * (purchasing.manage, checked by GoodsReceiptService).
 */
class GoodsReceiptPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'purchasing.receive';

    protected ?string $managePermission = 'purchasing.receive';

    public function viewAny(User $user): bool
    {
        return $user->can('purchasing.manage') || $user->can('purchasing.receive');
    }

    public function update(User $user, Model $record): bool
    {
        return $record instanceof GoodsReceipt && ! $record->isPosted() && $this->create($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }
}
