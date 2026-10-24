<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transfers between locations (docs/PHARMACY.md §5). A transfer is posted
 * when it is saved: each line writes a transfer_out at the source and a
 * transfer_in at the destination in one transaction, at the source
 * location's average cost. Posted transfers are never edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->string('transfer_number', 30);          // TR-000001, per shop
            $table->foreignId('from_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('locations')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('transferred_at');
            $table->timestamps();

            $table->unique(['shop_id', 'transfer_number']);
            $table->index(['shop_id', 'transferred_at']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_transfer_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('unit_name', 30);                // snapshot
            $table->decimal('unit_factor', 14, 3)->default(1); // snapshot
            $table->decimal('quantity', 14, 3);             // in the unit entered
            $table->decimal('base_quantity', 14, 3);        // what stock moved
            $table->decimal('unit_cost', 20, 4);            // source average per base unit (Q9)
            $table->foreignId('out_movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('in_movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->timestamps();

            $table->index('stock_transfer_id');
            $table->index('product_variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
