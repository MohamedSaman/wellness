<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Units a variant is counted, sold and bought in (docs/PHARMACY.md §4.2).
 * Stock is always held in the base unit; a pack (strip, box, carton) is a
 * named number of base units. Every variant gets its base row here, named
 * after products.unit, so retail items carry on as one unit of factor 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variant_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('name', 30);                         // "Tablet", "Strip", "Box"
            $table->decimal('factor', 14, 3)->default(1);       // base units in one of these; base = 1
            $table->string('barcode', 100)->nullable();         // pack barcode; the base uses the variant's
            $table->boolean('is_base')->default(false);         // exactly one per variant
            $table->boolean('is_sellable')->default(true);      // on the base row: allow_unit_sale
            $table->boolean('is_purchasable')->default(true);
            $table->boolean('is_default_sale')->default(false); // what a scan of the variant barcode adds
            $table->boolean('is_active')->default(true);        // deactivated, never deleted
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_variant_id', 'name']);
            $table->unique(['shop_id', 'barcode']);
        });

        // Idempotent: only variants without a base row, soft-deleted ones included
        // (old sale lines still point at them).
        DB::table('variant_units')->insertUsing(
            ['shop_id', 'product_variant_id', 'name', 'factor', 'is_base', 'is_sellable', 'is_purchasable', 'is_default_sale', 'is_active', 'sort_order', 'created_at', 'updated_at'],
            DB::table('product_variants')
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->whereNotExists(fn (Builder $base) => $base->from('variant_units')
                    ->whereColumn('variant_units.product_variant_id', 'product_variants.id')
                    ->where('variant_units.is_base', true))
                ->selectRaw("product_variants.shop_id, product_variants.id, COALESCE(NULLIF(products.unit, ''), 'pcs'), 1, ?, ?, ?, ?, ?, 0, ?, ?", [
                    true, true, true, true, true, now(), now(),
                ]),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_units');
    }
};
