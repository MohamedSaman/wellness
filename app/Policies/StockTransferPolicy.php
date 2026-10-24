<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Transfers between locations: anyone who sees stock can read them; making one needs stock.transfer. Never edited. */
class StockTransferPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'stock.view';

    protected ?string $managePermission = 'stock.transfer';

    public function update(User $user, Model $record): bool
    {
        return false;
    }
}
