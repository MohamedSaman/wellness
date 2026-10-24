<?php

namespace App\Policies;

/** Stock locations (multi_location). Owner only by default. Locations are deactivated, never deleted. */
class LocationPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'locations.manage';

    protected ?string $managePermission = 'locations.manage';
}
