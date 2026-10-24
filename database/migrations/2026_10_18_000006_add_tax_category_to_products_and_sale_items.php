<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product tax category (docs/PHARMACY.md Q11): the shop's tax rate
 * applies only to standard products. Every existing product becomes
 * standard, so retail tax is unchanged. Sale lines snapshot the category
 * they were taxed under; older lines stay null (they were all standard).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('tax_category', 20)->default('standard')->after('whole_units_only');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('tax_category', 20)->nullable()->after('tax_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('tax_category');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('tax_category');
        });
    }
};
