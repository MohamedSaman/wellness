<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for the admin panel policies. Filament authorizes resources, pages,
 * table actions and relation managers through Laravel policies, so these map
 * each ability onto the permissions in config/pos.php.
 *
 * Nothing in the panel deletes records: sales, stock movements and audit logs
 * are never removed (see ARCHITECTURE.md rule 6), and catalog records are
 * deactivated instead.
 */
abstract class PermissionPolicy
{
    /** Permission needed to see the list and individual records. */
    protected string $viewPermission = 'products.view';

    /** Permission needed to create and edit. Null = read-only. */
    protected ?string $managePermission = 'products.manage';

    public function viewAny(User $user): bool
    {
        return $user->can($this->viewPermission);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->managePermission !== null && $user->can($this->managePermission);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Model $record): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, Model $record): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
