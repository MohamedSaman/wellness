<?php

use App\Support\Quantity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Receipts (docs/PHARMACY.md §9, branch 9). Receipts and reprints are built
 * from the sale's own rows, never from the product as it is now, so the two
 * things a receipt prints that were still read from the product are copied
 * onto the line when it is sold:
 *
 * - sale_items.strength: the medicine's strength ("500 mg"), null for
 *   anything without one;
 * - sale_items.base_unit_name: what stock is counted in ("Tablet", "ml"), so
 *   a return of 10 tablets from a box prints as "10 Tablet".
 *
 * Existing lines are filled in from the product once, here. And:
 *
 * - sale_returns.refund_reference: the bank transfer (or card) reference of
 *   the refund.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('strength', 50)->nullable();
            $table->string('base_unit_name', 30)->nullable();
        });

        Schema::table('sale_returns', function (Blueprint $table) {
            $table->string('refund_reference', 100)->nullable();
        });

        DB::table('sale_items')
            ->join('product_variants', 'product_variants.id', '=', 'sale_items.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('drug_details', 'drug_details.product_id', '=', 'products.id')
            ->select(['sale_items.id', 'products.unit', 'drug_details.strength_value', 'drug_details.strength_unit'])
            ->orderBy('sale_items.id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('sale_items')->where('id', $row->id)->update([
                        'base_unit_name' => mb_substr(trim((string) $row->unit), 0, 30) ?: null,
                        'strength' => $row->strength_value === null
                            ? null
                            : mb_substr(trim(Quantity::format((float) $row->strength_value).' '.$row->strength_unit), 0, 50),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropColumn('refund_reference');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['strength', 'base_unit_name']);
        });
    }
};
