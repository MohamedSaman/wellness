<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every sellable unit has its own price (docs/PHARMACY.md §4.2, Q13): the
 * loose tablet, the strip and the box are priced independently. Existing
 * prices become the base unit's price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variant_prices', function (Blueprint $table) {
            $table->foreignId('variant_unit_id')->nullable()->after('product_variant_id')->constrained()->cascadeOnDelete();
        });

        DB::table('variant_prices')
            ->whereNull('variant_unit_id')
            ->update([
                'variant_unit_id' => DB::raw('(select variant_units.id from variant_units where variant_units.product_variant_id = variant_prices.product_variant_id and variant_units.is_base = 1)'),
            ]);

        Schema::table('variant_prices', function (Blueprint $table) {
            $table->foreignId('variant_unit_id')->nullable(false)->change();
            // New unique first: MySQL keeps using an index that starts with
            // product_variant_id for that foreign key while the old one is dropped.
            $table->unique(['product_variant_id', 'price_list_id', 'variant_unit_id', 'min_quantity'], 'variant_unit_price_unique');
        });

        Schema::table('variant_prices', function (Blueprint $table) {
            $table->dropUnique('variant_price_unique');
        });
    }

    public function down(): void
    {
        // Pack prices have no place in the old shape.
        DB::table('variant_prices')
            ->whereNotIn('variant_unit_id', DB::table('variant_units')->where('is_base', true)->select('id'))
            ->delete();

        Schema::table('variant_prices', function (Blueprint $table) {
            $table->unique(['product_variant_id', 'price_list_id', 'min_quantity'], 'variant_price_unique');
        });

        Schema::table('variant_prices', function (Blueprint $table) {
            $table->dropUnique('variant_unit_price_unique');
            $table->dropConstrainedForeignId('variant_unit_id');
        });
    }
};
