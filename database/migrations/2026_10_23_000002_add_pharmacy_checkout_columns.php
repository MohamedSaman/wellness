<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pharmacy checkout (docs/PHARMACY.md §9, branch 8). Every column has a
 * default that keeps retail sales exactly as they were.
 *
 * - devices.opd_billing: the till rings up OPD bills (consultation + one
 *   Medicines amount).
 * - products.allow_price_override: the till may type this product's price
 *   (honoured for non-stock products only, e.g. the consultation fee, §4.3).
 * - sales.bill_type ('standard' | 'opd'), opd_medicines_amount (the typed
 *   Medicines amount, minor units), clock_offset_seconds (offline: the till's
 *   clock minus the server's at upload, §7.5) and reported_at (the time reports
 *   group by: sold_at unless the till's clock was out; backfilled = sold_at).
 * - sale_items.list_price (the price-list unit price when the line was charged
 *   something else), is_opd_medicine, drug_schedule (snapshot), prescriber,
 *   prescription_ref and prescription_id (Phase 2 prescriptions; no foreign
 *   key yet) (§4.10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('opd_billing')->default(false);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('allow_price_override')->default(false);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->string('bill_type', 20)->default('standard');
            $table->bigInteger('opd_medicines_amount')->nullable();
            $table->integer('clock_offset_seconds')->nullable();
            $table->timestamp('reported_at')->nullable();

            $table->index(['shop_id', 'reported_at']);
        });

        // Reports keep grouping every existing sale by the time it was sold.
        DB::table('sales')->whereNull('reported_at')->update(['reported_at' => DB::raw('sold_at')]);

        Schema::table('sale_items', function (Blueprint $table) {
            $table->bigInteger('list_price')->nullable();
            $table->boolean('is_opd_medicine')->default(false);
            $table->string('drug_schedule', 20)->nullable();
            $table->string('prescriber', 150)->nullable();
            $table->string('prescription_ref', 100)->nullable();
            $table->unsignedBigInteger('prescription_id')->nullable();

            $table->index('prescription_id');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropIndex(['prescription_id']);
            $table->dropColumn(['list_price', 'is_opd_medicine', 'drug_schedule', 'prescriber', 'prescription_ref', 'prescription_id']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'reported_at']);
            $table->dropColumn(['bill_type', 'opd_medicines_amount', 'clock_offset_seconds', 'reported_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('allow_price_override');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('opd_billing');
        });
    }
};
