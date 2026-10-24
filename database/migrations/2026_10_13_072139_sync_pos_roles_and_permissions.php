<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Brings roles in line with config/pos.php on deploy. The admin panel now
 * needs the admin.access permission; without this, owners on an existing
 * install would be locked out of /admin until someone re-ran the seeder.
 * The seeder is idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new RolesAndPermissionsSeeder)->run();
    }

    public function down(): void
    {
        // Permissions are left in place; removing them could lock users out.
    }
};
