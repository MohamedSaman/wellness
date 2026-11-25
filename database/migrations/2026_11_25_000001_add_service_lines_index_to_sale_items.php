<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Patients summary's money (docs/PHARMACY.md, branch 20): the service
 * lines (consultations, lab tests) of the bills shown, read from the index
 * alone instead of every line of a year's bills.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->index(['sale_id', 'product_variant_id', 'line_total'], 'sale_items_sale_variant_total_index');
        });
    }

    public function down(): void
    {
        // On MySQL the new index took over from the foreign key's own index on sale_id
        // (it drops that one when another can serve the key): give the key one back first.
        Schema::table('sale_items', function (Blueprint $table) {
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->index('sale_id', 'sale_items_sale_id_foreign');
            }

            $table->dropIndex('sale_items_sale_variant_total_index');
        });
    }
};
