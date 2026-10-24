<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds pos.price_override (type the price of a service at the till, e.g. the
 * consultation fee) and pos.opd_bill (ring up OPD bills on an OPD till) and
 * gives both to the cashier role, as in config/pos.php, on deploy. The seeder
 * is idempotent.
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
