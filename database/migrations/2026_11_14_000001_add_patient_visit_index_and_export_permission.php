<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patient filters and the per-shift patient list (docs/PHARMACY.md,
 * branch 16). Additive only.
 *
 * - sales (patient_id, reported_at): a patient's first and last visit and
 *   the location of the last, read per row of the Patients list, and the
 *   visits of a period per patient.
 * - patients.export (Excel and PDF of the filtered Patients list), for the
 *   owner through '*', as in config/pos.php. The seeder is idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->index(['patient_id', 'reported_at']);
        });

        (new RolesAndPermissionsSeeder)->run();
    }

    public function down(): void
    {
        // On MySQL the new index took over from the foreign key's own index on patient_id
        // (it drops that one when another can serve the key): give the key one back first.
        // SQLite needs none, and one left behind would stop the patients migration's
        // rollback from dropping the column.
        Schema::table('sales', function (Blueprint $table) {
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->index('patient_id');
            }

            $table->dropIndex(['patient_id', 'reported_at']);
        });

        // Permissions are left in place; removing them could lock users out of features.
    }
};
