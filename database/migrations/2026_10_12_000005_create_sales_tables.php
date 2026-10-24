<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('opening_cash')->default(0);
            $table->unsignedBigInteger('expected_cash')->nullable();
            $table->unsignedBigInteger('counted_cash')->nullable();
            $table->bigInteger('difference')->nullable();   // counted - expected (can be negative)
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'closed_at']);
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();                 // created by the till, makes sync idempotent
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete(); // cashier
            $table->foreignId('price_list_id')->constrained()->restrictOnDelete();
            $table->string('invoice_no', 30);
            $table->string('status', 30)->default('completed');
            $table->unsignedBigInteger('subtotal');         // before discounts and added tax
            $table->unsignedBigInteger('discount_total')->default(0);
            $table->unsignedBigInteger('tax_total')->default(0);
            $table->unsignedBigInteger('grand_total');
            $table->unsignedBigInteger('paid_total');
            $table->unsignedBigInteger('change_due')->default(0);
            $table->unsignedBigInteger('refunded_total')->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);  // snapshot of shop setting
            $table->boolean('prices_include_tax')->default(true);
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 50)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('sold_at');
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamp('synced_at')->nullable();     // set when an offline sale reached the server
            $table->timestamps();

            $table->unique(['shop_id', 'invoice_no']);
            $table->index(['shop_id', 'sold_at']);
            $table->index(['shop_id', 'user_id', 'sold_at']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('description');                  // snapshot: product + variant name
            $table->string('sku', 100);                     // snapshot
            $table->decimal('quantity', 14, 3);
            $table->decimal('returned_quantity', 14, 3)->default(0);
            $table->unsignedBigInteger('unit_price');       // snapshot, minor units
            $table->unsignedBigInteger('unit_cost')->default(0); // snapshot for profit reports
            $table->unsignedBigInteger('line_discount')->default(0);
            $table->unsignedBigInteger('sale_discount_share')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('line_total');       // what the customer pays for this line
            $table->timestamps();

            $table->index('product_variant_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->string('method', 30);
            $table->unsignedBigInteger('amount');           // amount tendered, minor units
            $table->string('reference')->nullable();        // card approval code, transfer ref
            $table->timestamps();
        });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('return_no', 30);
            $table->string('reason');
            $table->unsignedBigInteger('refund_total');
            $table->string('refund_method', 30);
            $table->timestamp('returned_at');
            $table->timestamps();

            $table->unique(['shop_id', 'return_no']);
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->unsignedBigInteger('refund_amount');
            $table->boolean('restocked')->default(true);    // false = damaged, not put back
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 30);                    // created | updated | deleted | custom
            $table->nullableMorphs('auditable');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'sale_return_items', 'sale_returns', 'payments', 'sale_items', 'sales', 'shifts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
