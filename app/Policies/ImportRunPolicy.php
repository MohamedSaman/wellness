<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** The import log and importing: owner only (imports.manage). Runs are never edited or deleted. */
class ImportRunPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'imports.manage';

    protected ?string $managePermission = 'imports.manage';

    public function update(User $user, Model $record): bool
    {
        return false;
    }
}
