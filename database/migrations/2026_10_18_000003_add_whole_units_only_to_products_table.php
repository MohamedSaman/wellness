<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No half tablets: when on, every quantity sold, received, counted or
 * returned must be a whole number of base units. Off for existing (retail)
 * products, which keep selling by the litre or metre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('whole_units_only')->default(false)->after('track_stock');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('whole_units_only');
        });
    }
};
