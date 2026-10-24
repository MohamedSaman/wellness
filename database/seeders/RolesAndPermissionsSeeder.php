<?php

namespace Database\Seeders;

use App\Support\InstallProfile;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every permission from config/pos.php, and the roles the install
 * profile lists (so a retail shop never gets a Doctor role). Roles that
 * already exist are always brought in line with config/pos.php. Safe to
 * re-run any time you add permissions or roles.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    private ?InstallProfile $profile = null;

    /** Seed for this profile instead of the one POS_PROFILE selects (pos:install --profile). */
    public function forProfile(InstallProfile $profile): static
    {
        $this->profile = $profile;

        return $this;
    }

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = array_map(strval(...), array_keys(config('pos.permissions')));
        $profileRoles = ($this->profile ?? InstallProfile::current())->roles();

        foreach ($all as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (config('pos.roles') as $roleName => $permissions) {
            $role = in_array($roleName, $profileRoles, true)
                ? Role::findOrCreate($roleName, 'web')
                : Role::query()->where(['name' => $roleName, 'guard_name' => 'web'])->first();

            $role?->syncPermissions($permissions === ['*'] ? $all : $permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
