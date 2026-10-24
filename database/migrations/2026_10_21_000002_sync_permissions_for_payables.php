<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the payables.view permission (read the payables screen, supplier
 * balances and statements) to the roles in config/pos.php on deploy. The
 * seeder is idempotent.
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
