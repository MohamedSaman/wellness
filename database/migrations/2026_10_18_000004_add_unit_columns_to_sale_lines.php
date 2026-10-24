<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sale lines remember the unit they were sold in and how many base units
 * that was (docs/PHARMACY.md §4.2). quantity, unit_price and unit_cost stay
 * in the sold unit; base_quantity is what stock moved. Old lines were all
 * sold in the base unit, so base_quantity = quantity and unit_id stays null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('variant_unit_id')->nullable()->after('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('unit_name', 30)->nullable()->after('sku');
            $table->decimal('unit_factor', 14, 3)->default(1)->after('unit_name');
            $table->decimal('base_quantity', 14, 3)->default(0)->after('quantity');
        });

        Schema::table('sale_return_items', function (Blueprint $table) {
            $table->decimal('base_quantity', 14, 3)->default(0)->after('quantity');
        });

        DB::table('sale_items')->where('base_quantity', 0)->update(['base_quantity' => DB::raw('quantity')]);
        DB::table('sale_return_items')->where('base_quantity', 0)->update(['base_quantity' => DB::raw('quantity')]);
    }

    public function down(): void
    {
        Schema::table('sale_return_items', function (Blueprint $table) {
            $table->dropColumn('base_quantity');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('variant_unit_id');
            $table->dropColumn(['unit_name', 'unit_factor', 'base_quantity']);
        });
    }
};
