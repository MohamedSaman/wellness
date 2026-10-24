<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the reports (docs/PHARMACY.md §9, branch 11), so a year of
 * sales reports in about two seconds on MySQL:
 *
 * - sale_returns (shop_id, returned_at): refunds count on the day they
 *   were given, in every sales report;
 * - sales (shop_id, voided_at): voids on the day they were voided (daily
 *   summary, voids and returns);
 * - shifts (shop_id, opened_at): the shifts of a period (daily summary,
 *   shift history);
 * - stock_movements (shop_id, occurred_at): the stock movements report in
 *   time order, and its exports read a year by keyset.
 *
 * Sales by reported_at, ledger movements by their sale (the morph index),
 * goods received by date, transfers by date and batches by expiry are
 * indexed already.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->index(['shop_id', 'returned_at']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->index(['shop_id', 'voided_at']);
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->index(['shop_id', 'opened_at']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index(['shop_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'returned_at']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'voided_at']);
        });

        // On MySQL the new index took over from the foreign key's own index on shop_id
        // (it drops that one when another can serve the key): give the key one back first.
        Schema::table('shifts', function (Blueprint $table) {
            $table->index('shop_id');
            $table->dropIndex(['shop_id', 'opened_at']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'occurred_at']);
        });
    }
};
