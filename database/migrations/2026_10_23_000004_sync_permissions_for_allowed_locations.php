<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds locations.all (with multi_location, a user with no allowed locations
 * may use every location instead of only the shop's default) and gives it to
 * the owner through '*', as in config/pos.php, on deploy. The cashier does not
 * get it. The seeder is idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new RolesAndPermissionsSeeder)->run();
    }

    public function down(): void
    {
        // Permissions are left in place; removing them could lock users out of features.
    }
};
