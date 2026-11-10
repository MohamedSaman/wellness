<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Prices changed at the till (docs/PHARMACY.md, branch 14). Additive only.
 *
 * - products.ask_price_at_till: the till opens the price box as soon as the
 *   item is added (lab tests, procedures).
 * - product_variants.till_price_min / till_price_max: the lowest and highest
 *   unit price (base unit, minor units) the till may type. A pharmacy shop
 *   requires the maximum when the item's price can be changed; both null on
 *   an item switched on before this branch (no limit but "above zero").
 * - sale_items.price_changed_by: the cashier who typed the line's price.
 *   Null on every other line, so it also says which lines were typed (a
 *   typed price on a service with no list price leaves list_price null,
 *   like any ordinary line).
 *
 * backfill() fills price_changed_by for the typed lines sold before it, and
 * only those that are certain: until this branch list_price was set on a
 * line only when it was typed or an OPD bill's medicine line, so a line with
 * a list price that is not an OPD medicine was typed, by the sale's cashier.
 * A typed line of a service with no list price can't be told apart and stays
 * null. It only fills lines still null, so running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('ask_price_at_till')->default(false);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedBigInteger('till_price_min')->nullable();
            $table->unsignedBigInteger('till_price_max')->nullable();
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->unsignedBigInteger('price_changed_by')->nullable();

            $table->index('price_changed_by');
        });

        $updated = $this->backfill();
        $message = "sale_items.price_changed_by: {$updated} typed-price line(s) backfilled.";

        Log::info($message);

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln("  <info>{$message}</info>");
        }
    }

    /** Fills price_changed_by on the typed lines sold before it; returns how many lines it changed. */
    public function backfill(): int
    {
        return DB::table('sale_items')
            ->whereNull('price_changed_by')
            ->whereNotNull('list_price')
            ->where('is_opd_medicine', false)
            ->whereExists(fn ($sale) => $sale->select(DB::raw(1))
                ->from('sales')
                ->whereColumn('sales.id', 'sale_items.sale_id')
                ->whereNotNull('sales.user_id'))
            ->update([
                'price_changed_by' => DB::raw('(select sales.user_id from sales where sales.id = sale_items.sale_id)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropIndex(['price_changed_by']);
            $table->dropColumn('price_changed_by');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['till_price_min', 'till_price_max']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('ask_price_at_till');
        });
    }
};
