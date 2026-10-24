<?php

namespace App\Filament\Resources;

use App\Models\User;
use Filament\Resources\Resource;

/**
 * Base for every POS resource. Access is decided by the policies in
 * app/Policies, which map onto the permissions in config/pos.php; Filament
 * applies them to pages, table actions and relation managers alike.
 */
abstract class PermissionResource extends Resource
{
    protected static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
