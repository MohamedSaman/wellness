<?php

namespace App\Policies;

use App\Models\LabelPrint;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The label print log (labels module): whoever prints labels sees and
 * reopens their own prints; the owner (labels.manage) sees everyone's.
 * Prints are made by LabelService, never edited.
 */
class LabelPrintPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'labels.print';

    protected ?string $managePermission = null;

    public function viewAny(User $user): bool
    {
        return $user->can('labels.print') || $user->can('labels.manage');
    }

    public function view(User $user, Model $record): bool
    {
        return $user->can('labels.manage')
            || ($user->can('labels.print') && $record instanceof LabelPrint && (int) $record->user_id === (int) $user->getKey());
    }
}
