<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier opening balances for go-live, and reversing entries for
 * payments (docs/PHARMACY.md §4.9, as built).
 *
 * - supplier_opening_balances: what was owed to a supplier when the shop
 *   started using the system. One standing entry per supplier; never
 *   edited or deleted: a mistake is put right by a reversing entry (the
 *   amount negated, reversal_of_id), after which the right one is entered.
 * - supplier_payments.reversal_of_id: payments are corrected the same way
 *   (a negative payment that reverses the wrong one) instead of deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('as_of');                                 // the go-live date it was owed on
            $table->bigInteger('amount');                          // minor units; positive = owed to the supplier
            $table->text('note')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('supplier_opening_balances')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['supplier_id', 'as_of']);
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->foreignId('reversal_of_id')->nullable()->unique()->after('notes')->constrained('supplier_payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropForeign(['reversal_of_id']);
            $table->dropUnique(['reversal_of_id']);
            $table->dropColumn('reversal_of_id');
        });

        Schema::dropIfExists('supplier_opening_balances');
    }
};
