<?php

namespace App\Policies;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Purchase orders: read by whoever orders or receives goods (to receive
 * against them); made and changed with purchasing.manage, and only while
 * they are drafts. Never deleted: cancelled instead.
 */
class PurchaseOrderPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'purchasing.manage';

    protected ?string $managePermission = 'purchasing.manage';

    public function viewAny(User $user): bool
    {
        return $user->can('purchasing.manage') || $user->can('purchasing.receive');
    }

    public function update(User $user, Model $record): bool
    {
        return $record instanceof PurchaseOrder && $record->status === PurchaseOrderStatus::Draft && $this->create($user);
    }
}
