<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the permissions of returns and exchanges at the till
 * (docs/PHARMACY.md, branch 19), as in config/pos.php, on deploy:
 * pos.exchange for the owner and the cashier; pos.exchange.late for the
 * owner (through '*'). The seeder is idempotent.
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
