<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Patients (docs/PHARMACY.md, branch 13): their records and history are
 * health data, seen with dispensing_register.view. They are added at the
 * till (with a sale), changed with patients.manage (PatientService), and
 * never deleted.
 */
class PatientPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'dispensing_register.view';

    protected ?string $managePermission = null;

    public function update(User $user, Model $record): bool
    {
        return $user->can('patients.manage');
    }
}
