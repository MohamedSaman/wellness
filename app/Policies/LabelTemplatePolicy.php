<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Label templates (labels module): set up by the owner only (labels.manage). */
class LabelTemplatePolicy extends PermissionPolicy
{
    protected string $viewPermission = 'labels.manage';

    protected ?string $managePermission = 'labels.manage';

    public function delete(User $user, Model $record): bool
    {
        return $user->can('labels.manage');
    }
}
