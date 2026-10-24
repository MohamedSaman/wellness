<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax (VAT) on supplier invoices (docs/PHARMACY.md, client answers of
 * 2026-10-20). The clinic charges no tax on its sales, so the tax it pays a
 * supplier is part of what the goods cost: it is added into each line's
 * total and so into the cost per base unit, the batch and the average.
 *
 * - goods_receipt_items.tax: tax entered on the line, as the invoice shows it.
 * - goods_receipts.invoice_tax: tax entered once for the whole invoice,
 *   spread over the lines by their value after discounts (invoice_tax_share),
 *   like the invoice discount. A GRN has one or the other, never both.
 * - goods_receipts.line_tax_total: the lines' own tax added up.
 *
 * Existing GRNs had no tax: every new column starts at 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->bigInteger('line_tax_total')->default(0)->after('invoice_discount');
            $table->bigInteger('invoice_tax')->default(0)->after('line_tax_total');
        });

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->bigInteger('tax')->default(0)->after('invoice_discount_share');
            $table->bigInteger('invoice_tax_share')->default(0)->after('tax');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->dropColumn(['tax', 'invoice_tax_share']);
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropColumn(['line_tax_total', 'invoice_tax']);
        });
    }
};
