<?php

namespace App\Policies;

/**
 * Doctors (pharmacy, docs/PHARMACY.md branch 18): the owner by default
 * (doctors.manage), who also sees each doctor's payments. Deactivated,
 * never deleted.
 */
class DoctorPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'doctors.manage';

    protected ?string $managePermission = 'doctors.manage';
}
