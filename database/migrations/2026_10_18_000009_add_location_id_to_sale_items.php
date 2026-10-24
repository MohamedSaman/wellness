<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each sale line remembers the location its stock came from (docs/PHARMACY.md
 * §4.4), so one bill can take from two pools and returns and voids go back to
 * the right one. Every existing line came from its sale's location.
 * sales.location_id stays: where the bill was rung up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('product_variant_id')->constrained()->restrictOnDelete();
        });

        DB::table('sale_items')->whereNull('location_id')->update([
            'location_id' => DB::raw('(SELECT sales.location_id FROM sales WHERE sales.id = sale_items.sale_id)'),
        ]);

        Schema::table('sale_items', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        // SQLite rebuilds the table to drop a foreign key column; sale_return_items points at it.
        // Inside a transaction SQLite ignores foreign_keys = OFF, but honours deferred checks.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }

        Schema::withoutForeignKeyConstraints(fn () => Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        }));
    }
};
