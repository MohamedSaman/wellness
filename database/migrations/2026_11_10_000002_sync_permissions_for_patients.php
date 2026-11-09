<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the patients' permissions (docs/PHARMACY.md, branch 13):
 * dispensing_register.view (the dispensing register and the patients'
 * pages) and patients.manage (edit a patient), for the owner through '*',
 * as in config/pos.php, on deploy. The seeder is idempotent.
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
