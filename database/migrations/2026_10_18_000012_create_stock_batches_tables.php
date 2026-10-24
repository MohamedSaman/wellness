<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch and expiry stock (docs/PHARMACY.md §4.5, §6, branch 5).
 *
 * - A batch is one manufacturer batch of an item at one location (the same
 *   batch at General Pharmacy and Doctor's Medicines is two rows with the
 *   same number and expiry). Its quantity is a projection of the ledger:
 *   the sum of the movements that name it, kept by StockService and
 *   checked by pos:stock-rebuild.
 * - Each location has, per item, at most one "unallocated" batch that
 *   absorbs offline oversells (§6.4); only it may go below zero.
 * - The ledger names the batch of every movement of a batch-tracked item
 *   and keeps that batch's running balance, and the exact cost a receipt
 *   or transfer re-averaged with (for the cost rebuild).
 * - Each sale line records which batches it took (one row per batch, with
 *   the batch number and expiry as sold), so returns and voids go back to
 *   exactly those batches and receipts never depend on later edits.
 * - Products track batches when products.track_batches is on: every new
 *   medicine. Existing medicines without stock movements are switched on
 *   here; ones that already moved stock stay off (their stock has no batch).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('track_batches')->default(false)->after('track_stock');
        });

        Schema::table('drug_details', function (Blueprint $table) {
            $table->string('route', 20)->nullable()->after('dosage_form');             // DrugRoute
            $table->unsignedSmallInteger('min_days_to_expiry')->nullable()->after('storage_note'); // overrides the shop's
        });

        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('batch_no', 50);                    // as printed, upper case; '' for the unallocated batch
            $table->date('expiry_date')->nullable();           // last day of the printed month; null only for unallocated
            $table->decimal('unit_cost', 20, 4)->default(0);   // purchase cost per base unit (Q9), first receipt
            $table->string('status', 20)->default('active');   // BatchStatus
            $table->boolean('is_unallocated')->default(false);
            $table->decimal('quantity', 14, 3)->default(0);    // base units; projection of the ledger
            $table->timestamp('received_at');
            $table->nullableMorphs('source');                  // what first brought it in: movement, transfer, later a GRN
            $table->timestamps();

            $table->unique(['product_variant_id', 'location_id', 'batch_no', 'expiry_date'], 'stock_batches_identity_unique');
            $table->index(['location_id', 'product_variant_id', 'expiry_date'], 'stock_batches_fefo_index');
            $table->index(['shop_id', 'expiry_date']);       // expiry lists
            $table->index(['shop_id', 'is_unallocated']);    // unallocated stock alert
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('stock_batch_id')->nullable()->after('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('batch_balance_after', 14, 3)->nullable()->after('balance_after');
            $table->decimal('received_cost', 20, 4)->nullable()->after('unit_cost'); // exact cost a receipt or transfer re-averaged with

            $table->index(['stock_batch_id', 'id']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->json('till_batches')->nullable()->after('base_quantity'); // an offline till's proposal, when it was changed
        });

        Schema::create('sale_item_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_movement_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);                 // base units
            $table->decimal('returned_quantity', 14, 3)->default(0);
            $table->string('batch_no', 50);                     // snapshot, as printed on the receipt
            $table->date('expiry_date')->nullable();            // snapshot
            $table->boolean('is_unallocated')->default(false);  // snapshot: sold beyond the recorded stock
            $table->decimal('batch_unit_cost', 20, 4)->default(0); // snapshot, information only
            $table->timestamps();

            $table->index('sale_item_id');
            $table->index('stock_batch_id'); // who received batch X (recalls)
        });

        Schema::table('sale_return_items', function (Blueprint $table) {
            $table->json('batches')->nullable()->after('base_quantity'); // which batches came back, as returned
        });

        Schema::table('stock_transfer_items', function (Blueprint $table) {
            // A batch-tracked line can move several batches: its movements are on the batch rows.
            $table->unsignedBigInteger('out_movement_id')->nullable()->change();
            $table->unsignedBigInteger('in_movement_id')->nullable()->change();
        });

        Schema::create('stock_transfer_item_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_transfer_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_stock_batch_id')->constrained('stock_batches')->restrictOnDelete();
            $table->foreignId('to_stock_batch_id')->constrained('stock_batches')->restrictOnDelete();
            $table->string('batch_no', 50);           // snapshot
            $table->date('expiry_date')->nullable();  // snapshot
            $table->decimal('quantity', 14, 3);       // base units
            $table->foreignId('out_movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('in_movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->timestamps();

            $table->index('stock_transfer_item_id');
        });

        // The cost rebuild (pos:stock-rebuild --costs) replays received_cost. Before this
        // branch the ledger only kept whole minor units: a receipt's cost, a transfer's
        // rounded source average (the exact one is on the transfer line).
        DB::table('stock_movements')->whereIn('type', ['opening', 'stock_in'])->whereNotNull('unit_cost')
            ->update(['received_cost' => DB::raw('unit_cost')]);
        DB::table('stock_movements')->where('type', 'transfer_in')
            ->update(['received_cost' => DB::raw('COALESCE((SELECT stock_transfer_items.unit_cost FROM stock_transfer_items WHERE stock_transfer_items.in_movement_id = stock_movements.id), stock_movements.unit_cost)')]);

        // Every medicine tracks batches, except ones whose stock has already moved without them.
        DB::table('products')
            ->where('track_stock', true)
            ->whereIn('id', DB::table('drug_details')->select('product_id'))
            ->whereNotExists(fn ($movements) => $movements->selectRaw('1')
                ->from('stock_movements')
                ->join('product_variants', 'product_variants.id', '=', 'stock_movements.product_variant_id')
                ->whereColumn('product_variants.product_id', 'products.id'))
            ->update(['track_batches' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_item_batches');
        Schema::dropIfExists('sale_item_batches');

        // SQLite rebuilds a table to drop a foreign key column; other tables point at these.
        // Inside a transaction SQLite ignores foreign_keys = OFF, but honours deferred checks.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }

        Schema::withoutForeignKeyConstraints(function (): void {
            // MySQL uses the (stock_batch_id, id) index for the foreign key: drop the key first.
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->dropForeign(['stock_batch_id']);
            });

            Schema::table('stock_movements', function (Blueprint $table) {
                $table->dropIndex(['stock_batch_id', 'id']);
                $table->dropColumn(['stock_batch_id', 'batch_balance_after', 'received_cost']);
            });

            Schema::table('sale_items', fn (Blueprint $table) => $table->dropColumn('till_batches'));
            Schema::table('sale_return_items', fn (Blueprint $table) => $table->dropColumn('batches'));
        });

        Schema::dropIfExists('stock_batches');

        Schema::table('drug_details', fn (Blueprint $table) => $table->dropColumn(['route', 'min_days_to_expiry']));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('track_batches'));
        // stock_transfer_items keeps its movement columns nullable: batch lines may already have none.
    }
};
