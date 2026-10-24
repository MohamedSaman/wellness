<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registered tills. Each numbers its own invoices (T1-000245).
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('name');                         // "Front counter"
            $table->string('code', 10);                     // "T1" -> invoice prefix
            $table->unsignedBigInteger('next_invoice_number')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'code']);
        });

        // The stock ledger. Insert-only: stock is never edited directly.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->decimal('quantity', 14, 3);         // signed: +in / -out
            $table->decimal('balance_after', 14, 3);    // running balance at this location
            $table->unsignedBigInteger('unit_cost')->nullable(); // minor units, for valuation
            $table->nullableMorphs('reference');        // Sale, SaleReturn, PurchaseOrder ...
            $table->string('reason')->nullable();       // required for adjustments
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['product_variant_id', 'location_id', 'occurred_at']);
            $table->index(['shop_id', 'type', 'occurred_at']);
        });

        // Fast current-stock cache, kept in sync with the ledger by StockService.
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->timestamps();

            $table->unique(['location_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('devices');
    }
};
