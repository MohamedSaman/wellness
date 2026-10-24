<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the stock-control permissions (docs/PHARMACY.md §9, branch 10):
 * stock.write_off and stock.count for the owner (through '*'), and
 * stock.request for the owner and the cashier (a cashier at the OPD asks
 * General Pharmacy for stock; it does nothing without multi_location), as
 * in config/pos.php, on deploy. The seeder is idempotent.
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
