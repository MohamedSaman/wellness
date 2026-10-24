<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock counts measure each line from a snapshot (docs/PHARMACY.md §9,
 * branch 10, decided before merge): what the system held for the line (its
 * batch, or the item) at the moment it was counted, re-taken whenever the
 * line is counted again. Posting adjusts by counted - snapshot, so sales,
 * receipts and transfers between counting and posting are not variances.
 * For a batch-tracked item the unallocated stock (sold beyond its batches)
 * is snapshot too, for the count that settles it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_count_lines', function (Blueprint $table) {
            $table->decimal('counted_system_quantity', 14, 3)->nullable()->after('counted_unit_quantity');
            $table->decimal('counted_unallocated_quantity', 14, 3)->nullable()->after('counted_system_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('stock_count_lines', function (Blueprint $table) {
            $table->dropColumn(['counted_system_quantity', 'counted_unallocated_quantity']);
        });
    }
};
