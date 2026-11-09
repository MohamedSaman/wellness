<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patients on clinic sales and the dispensing register (docs/PHARMACY.md,
 * branch 13). Additive only: a new table and nullable columns, nothing
 * written to existing rows.
 *
 * - patients: one record per person, shared by every till and location
 *   (OPD and both pharmacies). The uuid is created by the till, so a patient
 *   added offline syncs once however many times its sales are sent. Name and
 *   date of birth only (a phone column can follow); the date of birth is
 *   worked out from the age when that is all the cashier typed
 *   (birth_date_estimated). Never deleted.
 * - sales.patient_id and sales.patient_age_months: the patient and their age
 *   at the visit, in months (shown "8 m" under 2 years, else "34 y"). The
 *   name as sold is sales.customer_name, which was already the patient's name
 *   on a pharmacy bill (the controlled-drug register reads it).
 * - sale_items.dosage_form: the medicine's form as sold (rule 8); lines sold
 *   before it are read from the medicine's drug details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->date('birth_date')->nullable();
            $table->boolean('birth_date_estimated')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete(); // the till that added them
            $table->timestamps();

            $table->index(['shop_id', 'name']);
            $table->index(['shop_id', 'updated_at']); // the till's offline copy syncs what changed
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('patient_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('patient_age_months')->nullable();

            $table->index(['shop_id', 'patient_id']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('dosage_form', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('dosage_form');
        });

        // SQLite rebuilds the table to drop a foreign key column; sale_items and payments point at it.
        // Inside a transaction SQLite ignores foreign_keys = OFF, but honours deferred checks.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }

        Schema::withoutForeignKeyConstraints(fn () => Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'patient_id']);
            $table->dropConstrainedForeignId('patient_id');
            $table->dropColumn('patient_age_months');
        }));

        Schema::dropIfExists('patients');
    }
};
