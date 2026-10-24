<?php

use App\Models\Shop;
use App\Services\SupplierPayablesService;
use App\Support\CurrentShop;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier payables (docs/PHARMACY.md, branch 6b): what is owed invoice by
 * invoice, when it is due, and which payments and credits settled it.
 *
 * - supplier_payables: one open item per posted GRN (not a reversal) and
 *   per opening balance entry owed at go-live: invoice number and date,
 *   due date (editable, audited), amount, what has been allocated to it so
 *   far, and its status (unpaid, part_paid, paid; reversed when the GRN or
 *   opening entry was reversed).
 * - supplier_credits: one per payment, supplier return and opening credit
 *   (a negative opening balance): its amount and how much of it has been
 *   allocated to payables. What is left is the supplier credit.
 * - supplier_allocations: credit x payable, insert-only. A mistake, a
 *   reversed payment or a reversed GRN writes a reversing row (the amount
 *   negated, reversal_of_id). The allocated counters on both sides are the
 *   sums of these rows.
 * - supplier_opening_balances gains invoice_no, invoice_date and due_on, so
 *   unpaid invoices from before go-live can be entered one by one.
 * - supplier_returns gains goods_receipt_id: the GRN the goods came from,
 *   whose outstanding the return's credit reduces.
 *
 * A supplier's balance (SupplierService::balance) is unchanged, and always
 * equals the payables outstanding less the credit left
 * (SupplierPayablesService::check). Existing purchasing data is brought in:
 * open items and credits for every document, payments and credits
 * allocated oldest due first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_opening_balances', function (Blueprint $table) {
            $table->string('invoice_no', 50)->nullable()->after('amount');   // null = a lump sum
            $table->date('invoice_date')->nullable()->after('invoice_no');
            $table->date('due_on')->nullable()->after('invoice_date');
        });

        Schema::table('supplier_returns', function (Blueprint $table) {
            $table->foreignId('goods_receipt_id')->nullable()->after('location_id')->constrained()->restrictOnDelete();
        });

        Schema::create('supplier_payables', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_opening_balance_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('invoice_no', 50)->nullable();          // the supplier's
            $table->date('invoice_date')->nullable();
            $table->date('document_date');                         // GRN received on / opening as of
            $table->date('due_on');
            $table->bigInteger('amount');                          // minor units, > 0
            $table->bigInteger('allocated')->default(0);           // payments and credits allocated to it
            $table->string('status', 20)->default('unpaid');       // PayableStatus
            $table->timestamps();

            $table->index(['shop_id', 'status', 'due_on']);
            $table->index(['supplier_id', 'status', 'due_on']);
        });

        Schema::create('supplier_credits', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_payment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_return_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_opening_balance_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->date('credit_on');                             // paid on / returned on / opening as of
            $table->bigInteger('amount');                          // minor units, > 0
            $table->bigInteger('allocated')->default(0);
            $table->boolean('is_reversed')->default(false);        // the payment or opening entry was reversed
            $table->timestamps();

            $table->index(['supplier_id', 'is_reversed']);
        });

        Schema::create('supplier_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_credit_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_payable_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount');                          // minor units; negative on a reversal
            $table->date('allocated_on');
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('supplier_allocations')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('supplier_credit_id');
            $table->index('supplier_payable_id');
            $table->index(['supplier_id', 'allocated_on']);
        });

        $this->bringInExistingDocuments();
    }

    /** Shops that already used purchasing get their open items, credits and allocations. */
    private function bringInExistingDocuments(): void
    {
        $shopIds = DB::table('suppliers')->distinct()->pluck('shop_id');

        foreach (Shop::query()->whereKey($shopIds->all())->get() as $shop) {
            app(CurrentShop::class)->runAs($shop, fn () => app(SupplierPayablesService::class)->bringIn());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_allocations');
        Schema::dropIfExists('supplier_credits');
        Schema::dropIfExists('supplier_payables');

        Schema::table('supplier_returns', function (Blueprint $table) {
            $table->dropForeign(['goods_receipt_id']);
            $table->dropColumn('goods_receipt_id');
        });

        Schema::table('supplier_opening_balances', function (Blueprint $table) {
            $table->dropColumn(['invoice_no', 'invoice_date', 'due_on']);
        });
    }
};
