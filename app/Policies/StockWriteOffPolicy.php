<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Write-off notes: anyone who sees stock can read them; writing off needs stock.write_off (from the Expiry screen). Never edited. */
class StockWriteOffPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'stock.view';

    protected ?string $managePermission = 'stock.write_off';

    public function update(User $user, Model $record): bool
    {
        return false;
    }
}
