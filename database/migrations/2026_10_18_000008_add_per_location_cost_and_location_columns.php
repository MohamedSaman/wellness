<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * multi_location made real (docs/PHARMACY.md §4.4, §5).
 *
 * - Weighted average cost moves from the variant to each location's stock
 *   level, as DECIMAL(20,4) minor units per base unit (Q9). Every existing
 *   level starts at its variant's average and last cost, so a one-location
 *   shop has exactly the figures it had before.
 * - A location can override the variant's reorder level.
 * - Locations can be kept out of selling (a back store, quarantine) and ordered.
 * - A user can have a default location (Stock In, transfers, later the till).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->boolean('is_sellable')->default(true)->after('is_active');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('is_sellable');
        });

        Schema::table('stock_levels', function (Blueprint $table) {
            $table->decimal('average_cost', 20, 4)->default(0)->after('quantity');
            $table->decimal('last_cost', 20, 4)->nullable()->after('average_cost');
            $table->decimal('reorder_level', 14, 3)->nullable()->after('last_cost');
        });

        DB::table('stock_levels')->update([
            'average_cost' => DB::raw('COALESCE((SELECT product_variants.cost_price FROM product_variants WHERE product_variants.id = stock_levels.product_variant_id), 0)'),
            'last_cost' => DB::raw('(SELECT product_variants.last_cost_price FROM product_variants WHERE product_variants.id = stock_levels.product_variant_id)'),
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('default_location_id')->nullable()->after('shop_id')->constrained('locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // SQLite rebuilds the table to drop a foreign key column; other tables point at users.
        // Inside a transaction SQLite ignores foreign_keys = OFF, but honours deferred checks.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }

        Schema::withoutForeignKeyConstraints(fn () => Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_location_id');
        }));

        Schema::table('stock_levels', function (Blueprint $table) {
            $table->dropColumn(['average_cost', 'last_cost', 'reorder_level']);
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['is_sellable', 'sort_order']);
        });
    }
};
