<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An OPD bill's medicines at list price (docs/PHARMACY.md, decided
 * 2026-10-22): sales.opd_medicines_list_value (Σ list price × quantity of
 * its priced medicine lines, minor units; never a cost) and
 * sales.opd_below_list (the Medicines amount was below the shop's warning
 * threshold of that value), for a later report or flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->bigInteger('opd_medicines_list_value')->nullable();
            $table->boolean('opd_below_list')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['opd_medicines_list_value', 'opd_below_list']);
        });
    }
};
