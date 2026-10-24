<?php

namespace App\Policies;

/** Tills and their offline access. Owner only by default. Tills are disabled, never deleted. */
class DevicePolicy extends PermissionPolicy
{
    protected string $viewPermission = 'tills.manage';

    protected ?string $managePermission = 'tills.manage';
}
