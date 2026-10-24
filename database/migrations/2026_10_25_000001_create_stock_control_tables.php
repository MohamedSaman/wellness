<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock control (docs/PHARMACY.md §9, branch 10):
 *
 *   - stock_levels.par_level: what a location is topped up to (reorder
 *     suggestions); the reorder level says when, the par level how far;
 *   - stock_write_offs (+ items): expired or damaged batches written off,
 *     valued at what each batch cost; the stock leaves as adjustment_out
 *     movements that reference the write-off;
 *   - stock_requests (+ items): a location (the OPD) asking another (General
 *     Pharmacy) for stock; fulfilling one posts transfers that name it
 *     (stock_transfers.stock_request_id);
 *   - stock_counts (+ lines): a stock take as a document, per location and
 *     per batch, posted as count corrections in one transaction.
 *
 * Posted write-offs never change; requests and counts only move forward
 * through their statuses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_levels', function (Blueprint $table) {
            $table->decimal('par_level', 14, 3)->nullable()->after('reorder_level'); // top up to this, base units
        });

        Schema::create('stock_write_offs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->string('write_off_number', 30);         // WO-000001, per shop
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('reason', 20);                   // AdjustmentReason: expired, damaged, other
            $table->text('note')->nullable();
            $table->bigInteger('total')->default(0);        // value at batch cost, minor units
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('written_off_at');
            $table->timestamps();

            $table->unique(['shop_id', 'write_off_number']);
            $table->index(['shop_id', 'written_off_at']);
        });

        Schema::create('stock_write_off_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_write_off_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_batch_id')->constrained()->restrictOnDelete();
            $table->string('batch_no', 50);                 // snapshot
            $table->date('expiry_date')->nullable();        // snapshot
            $table->decimal('quantity', 14, 3);             // base units
            $table->decimal('unit_cost', 20, 4);            // the batch's purchase cost per base unit (Q9)
            $table->bigInteger('line_total');               // quantity x unit cost, rounded
            $table->foreignId('stock_movement_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->index('stock_write_off_id');
            $table->index('stock_batch_id');
        });

        Schema::create('stock_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->string('request_number', 30);           // RQ-000001, per shop
            $table->foreignId('from_location_id')->constrained('locations')->restrictOnDelete(); // asked to send
            $table->foreignId('to_location_id')->constrained('locations')->restrictOnDelete();   // asking
            $table->string('status', 20);                   // StockRequestStatus
            $table->text('note')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('closed_at')->nullable();     // sent in full, or cancelled
            $table->timestamps();

            $table->unique(['shop_id', 'request_number']);
            $table->index(['shop_id', 'status']);
            $table->index(['to_location_id', 'status']);
        });

        Schema::create('stock_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('unit_name', 30);                // snapshot
            $table->decimal('unit_factor', 14, 3)->default(1); // snapshot
            $table->decimal('quantity', 14, 3);             // in the unit asked for
            $table->decimal('base_quantity', 14, 3);        // what is asked for, base units
            $table->decimal('sent_base_quantity', 14, 3)->default(0); // sent so far, base units
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('stock_request_id');
            $table->index('product_variant_id');
        });

        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->foreignId('stock_request_id')->nullable()->after('to_location_id')->constrained()->restrictOnDelete();
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->string('count_number', 30);             // SC-000001, per shop
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);                   // StockCountStatus
            $table->boolean('blind')->default(false);       // the sheet and the entry screen hide system quantities
            $table->json('scope')->nullable();              // what was put on the sheet: category, shelf / bin
            $table->text('note')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->bigInteger('variance_value')->nullable(); // net value of the posted variances, minor units
            $table->timestamps();

            $table->unique(['shop_id', 'count_number']);
            $table->index(['shop_id', 'status']);
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_count_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_batch_id')->nullable()->constrained()->restrictOnDelete(); // null: no batches, or a batch found on the shelf
            $table->string('batch_no', 50)->nullable();     // batch-tracked lines: as on record, or as found
            $table->date('expiry_date')->nullable();
            $table->decimal('expected_quantity', 14, 3)->default(0); // on hand when the line was put on the sheet
            $table->decimal('counted_quantity', 14, 3)->nullable();  // base units; null = not counted
            $table->foreignId('counted_unit_id')->nullable()->constrained('variant_units')->restrictOnDelete();
            $table->decimal('counted_unit_quantity', 14, 3)->nullable(); // as typed, in that unit
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('counted_at')->nullable();
            $table->decimal('system_quantity', 14, 3)->nullable(); // held for the line at posting, before the variance
            $table->decimal('variance', 14, 3)->nullable();        // written at posting: counted - the snapshot taken when counted
            $table->decimal('unit_cost', 20, 4)->nullable();       // the location's average at posting (Q9)
            $table->bigInteger('variance_value')->nullable();
            $table->foreignId('stock_movement_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('stock_count_id');
            $table->index(['stock_count_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');

        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_request_id');
        });

        Schema::dropIfExists('stock_request_items');
        Schema::dropIfExists('stock_requests');
        Schema::dropIfExists('stock_write_off_items');
        Schema::dropIfExists('stock_write_offs');

        Schema::table('stock_levels', function (Blueprint $table) {
            $table->dropColumn('par_level');
        });
    }
};
