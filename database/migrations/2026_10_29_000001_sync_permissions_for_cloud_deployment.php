<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the cloud deployment's permissions (docs/deploy/CLOUD.md):
 * system.health (the System health page) and backups.download (the latest
 * encrypted backup), for the owner through '*', as in config/pos.php, on
 * deploy. The seeder is idempotent.
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
