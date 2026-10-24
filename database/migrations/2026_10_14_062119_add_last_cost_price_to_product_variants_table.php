<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Costing switches to weighted average cost: cost_price becomes the running
 * average, and last_cost_price keeps what the latest delivery cost.
 * Existing variants take the unit cost of their latest Opening or Stock In
 * movement (or the current cost when there is none).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedBigInteger('last_cost_price')->nullable()->after('cost_price'); // minor units
        });

        DB::table('product_variants')->orderBy('id')->chunkById(500, function ($variants): void {
            foreach ($variants as $variant) {
                $last = DB::table('stock_movements')
                    ->where('product_variant_id', $variant->id)
                    ->whereIn('type', ['opening', 'stock_in'])
                    ->whereNotNull('unit_cost')
                    ->orderByDesc('occurred_at')
                    ->orderByDesc('id')
                    ->value('unit_cost');

                DB::table('product_variants')->where('id', $variant->id)->update([
                    'last_cost_price' => $last ?? $variant->cost_price,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('last_cost_price');
        });
    }
};
