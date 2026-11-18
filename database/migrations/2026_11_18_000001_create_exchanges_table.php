<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Returns and exchanges at the till (docs/PHARMACY.md, branch 19). Additive
 * only: one new table and three columns with defaults, nothing written to
 * existing rows.
 *
 * - exchanges: one till transaction, numbered EX-000001 per shop: the
 *   return (of a bill's lines, sale_return_id, or without a bill,
 *   no_receipt_return_id) and, unless it was a plain return, the new bill
 *   (new_sale_id), with the credit, how much of it paid the new bill, what
 *   the patient paid on top and the cash refunded from the till's drawer.
 *   The till's uuid makes a retry return the same exchange.
 * - sale_returns.exchange_credit / no_receipt_returns.exchange_credit: the
 *   part of refund_total that paid a new bill (payment method "exchange")
 *   instead of leaving the drawer: cash refunded = refund_total −
 *   exchange_credit. 0 on every other return.
 * - sale_return_items.location_id: where a till return sent the stock
 *   (Quarantine, or back on sale at the till's location); null on returns
 *   before it (the line's own location, when restocked).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchanges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete(); // the till's
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reason', 30);                   // not_effective | side_effects | wrong_medicine | other
            $table->text('note')->nullable();
            $table->foreignId('original_sale_id')->nullable()->constrained('sales')->restrictOnDelete();
            $table->foreignId('sale_return_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('no_receipt_return_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('new_sale_id')->nullable()->constrained('sales')->restrictOnDelete();
            $table->unsignedBigInteger('credit_total');     // the return's value, minor units
            $table->unsignedBigInteger('credit_applied');   // min(credit, new bill): paid the new bill
            $table->unsignedBigInteger('new_total')->default(0);
            $table->unsignedBigInteger('patient_paid')->default(0);  // new bill − credit applied
            $table->unsignedBigInteger('cash_refunded')->default(0); // credit − credit applied, from the drawer
            $table->timestamp('exchanged_at');
            $table->timestamps();

            $table->unique(['shop_id', 'number']);
            $table->index(['shop_id', 'exchanged_at']);
            $table->index('shift_id');
            $table->index(['patient_id', 'exchanged_at']);
        });

        Schema::table('sale_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('exchange_credit')->default(0);
        });

        Schema::table('no_receipt_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('exchange_credit')->default(0);
        });

        Schema::table('sale_return_items', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }

        Schema::withoutForeignKeyConstraints(function (): void {
            Schema::table('sale_return_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('location_id'));
            Schema::table('no_receipt_returns', fn (Blueprint $table) => $table->dropColumn('exchange_credit'));
            Schema::table('sale_returns', fn (Blueprint $table) => $table->dropColumn('exchange_credit'));
        });

        Schema::dropIfExists('exchanges');
    }
};
