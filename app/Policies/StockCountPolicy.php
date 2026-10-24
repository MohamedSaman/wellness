<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Stock counts: stock.count starts them and enters what was counted;
 * stock.adjust (the owner) reviews and posts them, so both may read them.
 * Changed only through StockCountService.
 */
class StockCountPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'stock.count';

    protected ?string $managePermission = 'stock.count';

    public function viewAny(User $user): bool
    {
        return $user->can('stock.count') || $user->can('stock.adjust');
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }
}
