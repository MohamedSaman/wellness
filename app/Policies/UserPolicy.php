<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Owner only. Users are disabled, never deleted. */
class UserPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'users.manage';

    protected ?string $managePermission = 'users.manage';

    public function update(User $user, Model $record): bool
    {
        return $record instanceof User
            && (int) $record->shop_id === (int) $user->shop_id
            && parent::update($user, $record);
    }
}
