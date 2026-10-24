<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers and purchasing (docs/PHARMACY.md §4.6 to §4.9, branch 6). Generic:
 * a retail shop can switch the modules on too; batch and expiry columns are
 * only filled for batch-tracked items.
 *
 * - suppliers: the supplier list (suppliers module), deactivated, never deleted.
 * - purchase_orders (+ items): an optional order, in any unit of each item;
 *   it never moves stock. draft -> sent -> partially_received -> received,
 *   or cancelled. Items count what has arrived in base units.
 * - goods_receipts (+ items): goods received, with or without an order.
 *   Drafts are edited freely; posting writes stock_in movements through
 *   StockService (batches, the location's average cost) and numbers the
 *   GRN. Posted GRNs never change: a mistake is put right by a reversing
 *   GRN, whose lines are the original's negated. Totals, supplier, date and
 *   lines are kept so purchases by supplier and month can be reported.
 * - supplier_returns (+ items): stock sent back from named batches at a
 *   location, posted when saved (supplier_return movements at batch cost).
 * - supplier_payments: what was paid. A supplier's balance is posted GRN
 *   totals minus returns minus payments; no accounting integration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('contact_name', 150)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('address')->nullable();
            $table->string('tax_number', 50)->nullable();          // tax / business registration number
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['shop_id', 'name']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->string('po_number', 30);                       // PO-000001, per shop
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete(); // deliver to
            $table->string('status', 20)->default('draft');        // PurchaseOrderStatus
            $table->date('expected_on')->nullable();
            $table->bigInteger('total')->default(0);               // expected, minor units
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'po_number']);
            $table->index(['shop_id', 'status']);
            $table->index(['supplier_id', 'created_at']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_unit_id')->constrained()->restrictOnDelete();
            $table->string('unit_name', 30);                       // snapshot
            $table->decimal('unit_factor', 14, 3)->default(1);     // snapshot
            $table->decimal('quantity', 14, 3);                    // in the unit ordered
            $table->decimal('base_quantity', 14, 3);
            $table->bigInteger('unit_cost')->default(0);           // expected, minor per unit ordered
            $table->bigInteger('line_total')->default(0);
            $table->decimal('received_base_quantity', 14, 3)->default(0); // paid quantity received so far, base units
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('purchase_order_id');
            $table->index('product_variant_id');
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->string('grn_number', 30)->nullable();          // GRN-000001, given when posted
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('supplier_invoice_no', 50)->nullable();
            $table->date('supplier_invoice_date')->nullable();
            $table->date('received_on');                           // the month purchases are reported in
            $table->string('status', 20)->default('draft');        // GoodsReceiptStatus
            $table->bigInteger('subtotal')->default(0);            // quantity x cost, before discounts
            $table->bigInteger('line_discount_total')->default(0);
            $table->bigInteger('invoice_discount')->default(0);    // spread over the lines by value
            $table->bigInteger('total')->default(0);               // what is owed for it; negative on a reversal
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('goods_receipts')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();        // entered by
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'grn_number']);
            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'received_on']);
            $table->index(['supplier_id', 'received_on']);
            $table->index(['supplier_id', 'supplier_invoice_no']);
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('goods_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reversal_of_item_id')->nullable()->constrained('goods_receipt_items')->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_unit_id')->constrained()->restrictOnDelete();
            $table->string('unit_name', 30);                       // snapshot
            $table->decimal('unit_factor', 14, 3)->default(1);     // snapshot
            $table->decimal('quantity', 14, 3);                    // paid, in the unit received
            $table->decimal('free_quantity', 14, 3)->default(0);   // bonus goods ("10 + 1"), same unit
            $table->bigInteger('unit_cost')->default(0);           // minor per unit received, before discount
            $table->bigInteger('gross')->default(0);               // quantity x unit cost
            $table->bigInteger('discount')->default(0);            // line discount
            $table->bigInteger('invoice_discount_share')->default(0);
            $table->bigInteger('line_total')->default(0);          // gross - discount - share
            $table->decimal('base_quantity', 14, 3)->default(0);   // (quantity + free) x factor: what stock moves
            $table->decimal('base_unit_cost', 20, 4)->nullable();  // line_total / base_quantity (Q9), set when posted
            $table->string('batch_no', 50)->nullable();            // batch-tracked items only
            $table->date('expiry_date')->nullable();
            $table->boolean('accept_short_expiry')->default(false);
            $table->foreignId('stock_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('goods_receipt_id');
            $table->index(['product_variant_id', 'goods_receipt_id']);
        });

        Schema::create('supplier_returns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->string('return_number', 30);                   // SR-000001, per shop
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('reason', 20);                          // SupplierReturnReason
            $table->string('credit_note_no', 50)->nullable();
            $table->bigInteger('total')->default(0);               // credit expected, at batch cost
            $table->date('returned_on');
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['shop_id', 'return_number']);
            $table->index(['shop_id', 'returned_on']);
            $table->index(['supplier_id', 'returned_on']);
        });

        Schema::create('supplier_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_batch_id')->nullable()->constrained()->restrictOnDelete(); // null for items without batches
            $table->string('batch_no', 50)->nullable();            // snapshot
            $table->date('expiry_date')->nullable();               // snapshot
            $table->foreignId('variant_unit_id')->constrained()->restrictOnDelete();
            $table->string('unit_name', 30);
            $table->decimal('unit_factor', 14, 3)->default(1);
            $table->decimal('quantity', 14, 3);                    // in the unit entered
            $table->decimal('base_quantity', 14, 3);
            $table->decimal('unit_cost', 20, 4);                   // per base unit: the batch's purchase cost
            $table->bigInteger('line_total');
            $table->foreignId('stock_movement_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->index('supplier_return_id');
            $table->index('stock_batch_id');
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('paid_on');
            $table->bigInteger('amount');                          // minor units
            $table->string('method', 20);                          // SupplierPaymentMethod
            $table->string('reference', 100)->nullable();          // cheque or transfer number
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['supplier_id', 'paid_on']);
            $table->index(['shop_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('supplier_return_items');
        Schema::dropIfExists('supplier_returns');
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
