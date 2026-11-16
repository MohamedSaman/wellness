<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the permissions of cash movements, doctor payments and returns
 * without a receipt (docs/PHARMACY.md, branch 18), as in config/pos.php, on
 * deploy: cash.movements and doctors.pay for the owner and the cashier;
 * cash.movements.void, returns.no_receipt and doctors.manage for the owner
 * (through '*'). The seeder is idempotent.
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
