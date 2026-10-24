<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A structured storage condition (room, cool, fridge) next to the free-text
 * note, which the returns work reads (fridge items are not restocked by
 * default), and the medicine's NMRA registration number. Both optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drug_details', function (Blueprint $table) {
            $table->string('storage_condition', 10)->nullable()->after('schedule'); // StorageCondition
            $table->string('registration_no', 50)->nullable()->after('storage_note'); // NMRA registration
        });
    }

    public function down(): void
    {
        Schema::table('drug_details', function (Blueprint $table) {
            $table->dropColumn(['storage_condition', 'registration_no']);
        });
    }
};
