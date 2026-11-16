<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash movements in a shift, doctor payments and returns without a receipt
 * (docs/PHARMACY.md, branch 18). Additive only: new tables and one nullable
 * column, nothing written to existing rows.
 *
 * - doctors: the clinic's doctors, paid in cash from a till (pharmacy).
 *   Deactivated, never deleted: their payments keep them.
 * - cash_movements: cash paid out of or into a till's drawer during its
 *   open shift (paid_out, paid_in, doctor_payment), amount in minor units,
 *   numbered CM-000001 per shop. Expected cash = ... − paid out − doctor
 *   payments + paid in. Voided (owner, while the shift is open), never
 *   deleted.
 * - no_receipt_returns (+ items): a refund for goods bought before the
 *   system went live or without the bill, numbered NR-000001 per shop. The
 *   stock goes to Quarantine or back to sellable stock at the till's
 *   location; the cash comes out of the till's drawer.
 * - sale_returns.device_id: the till whose drawer paid an admin refund
 *   (its open shift is sale_returns.shift_id); null on returns before it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('phone', 50)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['shop_id', 'name']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);                     // paid_out | paid_in | doctor_payment
            $table->string('category', 30)->nullable();     // refund_difference | expense | float_top_up | other
            $table->unsignedBigInteger('amount');           // minor units, above zero
            $table->foreignId('doctor_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'number']);
            $table->index(['shop_id', 'created_at']);
            $table->index(['shift_id', 'type']);
            $table->index(['doctor_id', 'created_at']);
        });

        Schema::create('no_receipt_returns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete(); // the till's
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('reason');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('refund_total');
            $table->string('refund_method', 30);            // cash
            $table->timestamp('returned_at');
            $table->timestamps();

            $table->unique(['shop_id', 'number']);
            $table->index(['shop_id', 'returned_at']);
            $table->index('shift_id');
        });

        Schema::create('no_receipt_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('no_receipt_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description');                  // snapshot
            $table->string('sku', 100);                     // snapshot
            $table->string('unit_name', 30)->nullable();    // the unit returned, as named then
            $table->decimal('unit_factor', 14, 3)->default(1);
            $table->decimal('quantity', 14, 3);             // in that unit
            $table->decimal('base_quantity', 14, 3);
            $table->unsignedBigInteger('list_price');       // the unit's price at the time, minor units
            $table->unsignedBigInteger('unit_value');       // the value refunded per unit (typed, or the list price)
            $table->unsignedBigInteger('refund_amount');    // unit_value x quantity
            $table->foreignId('location_id')->constrained()->restrictOnDelete(); // where the stock went
            $table->foreignId('stock_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('batch_no', 50)->nullable();     // as typed; null = batch unknown (Quarantine only)
            $table->date('expiry_date')->nullable();
            $table->unsignedBigInteger('unit_cost')->default(0); // the location's average per base unit then
            $table->timestamps();
        });

        Schema::table('sale_returns', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }

        Schema::withoutForeignKeyConstraints(fn () => Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
        }));

        Schema::dropIfExists('no_receipt_return_items');
        Schema::dropIfExists('no_receipt_returns');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('doctors');
    }
};
