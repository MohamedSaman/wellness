<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Barcode labels (`labels` module, docs/PHARMACY.md §9, branch 7b):
 *
 *   - label_templates: what a label looks like, per shop: a thermal roll
 *     (one label per page) or an A4 sticker sheet (columns x rows on a page),
 *     its size, margins and gaps in mm, the printer's resolution (barcode
 *     bars are whole printer dots), and which fields it prints;
 *   - label_queue_items: labels a user has collected to print in one go
 *     (from a product, a delivery, batches or the product list);
 *   - label_prints: the print log (who printed what, how many labels, with
 *     which template). A print is a list of items and copies; opening it
 *     again prints the labels again. Not audited.
 *
 * Sizes are millimetres, not money, so they are plain decimals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('label_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('kind', 10);                         // LabelKind: roll | sheet
            $table->string('preset', 30)->nullable();           // config('pos.labels.presets') key; null = custom
            $table->decimal('label_width', 6, 2);               // mm
            $table->decimal('label_height', 6, 2);              // mm
            $table->unsignedTinyInteger('columns')->default(1); // sheets: labels across a page
            $table->unsignedTinyInteger('rows')->default(1);    // sheets: labels down a page
            $table->decimal('page_width', 6, 2)->nullable();    // sheets: mm (A4 = 210)
            $table->decimal('page_height', 6, 2)->nullable();   // sheets: mm (A4 = 297)
            $table->decimal('margin_top', 5, 2)->default(0);    // sheets: page edge to the first row
            $table->decimal('margin_left', 5, 2)->default(0);   // sheets: page edge to the first column
            $table->decimal('gap_x', 5, 2)->default(0);         // sheets: between columns
            $table->decimal('gap_y', 5, 2)->default(0);         // sheets: between rows
            $table->decimal('padding', 4, 2)->default(1.5);     // blank edge inside each label
            $table->unsignedSmallInteger('dpi')->default(203);  // printer resolution: 203, 300 or 600
            $table->json('fields');                             // field key => printed?
            $table->string('shop_name', 40)->nullable();        // the short name printed at the top
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['shop_id', 'is_default']);
        });

        Schema::create('label_queue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_unit_id')->nullable()->constrained()->restrictOnDelete(); // null = the base unit
            $table->foreignId('stock_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('batch_no', 50)->nullable();         // printed when the template prints the batch
            $table->date('expiry_date')->nullable();            // printed when the template prints the expiry
            $table->unsignedInteger('quantity');                // copies
            $table->string('source', 20);                       // product | delivery | batches | products
            $table->string('source_label', 60)->nullable();     // GRN-000012
            $table->timestamps();

            $table->index(['shop_id', 'user_id']);
        });

        Schema::create('label_prints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('label_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template_name', 100);               // snapshot, for the log
            $table->string('source', 20);                       // product | delivery | batches | products | queue
            $table->string('source_label', 60)->nullable();
            $table->unsignedInteger('labels_count');
            $table->json('items');                              // list of {variant_id, unit_id, batch_id, batch_no, expiry, quantity, name, unit, barcode}
            $table->timestamps();

            $table->index(['shop_id', 'created_at']);
            $table->index(['shop_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_prints');
        Schema::dropIfExists('label_queue_items');
        Schema::dropIfExists('label_templates');
    }
};
