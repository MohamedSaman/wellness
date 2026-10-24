<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the label permissions (docs/PHARMACY.md §9, branch 7b): labels.print
 * and labels.manage, for the owner (through '*'), as in config/pos.php, on
 * deploy. A cashier can be given labels.print. The seeder is idempotent.
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
