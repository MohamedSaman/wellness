<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The drug master (docs/PHARMACY.md §4.1): generic names, the medicine
 * details of a product (1:1) and which generics a product contains (more
 * than one for combinations such as Amoxicillin + Clavulanic acid).
 * A product without drug details is not a medicine (retail items, a
 * consultation fee, sundries).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);  // "Paracetamol"
            $table->string('slug', 150);
            $table->timestamps();

            $table->unique(['shop_id', 'slug']);
            $table->index(['shop_id', 'name']); // search by generic name
        });

        Schema::create('drug_details', function (Blueprint $table) {
            $table->foreignId('product_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->string('brand_name', 150)->nullable();       // "Panadol"
            $table->decimal('strength_value', 14, 3)->nullable(); // 500, 5
            $table->string('strength_unit', 20)->nullable();      // "mg", "mg/5 ml"
            $table->string('dosage_form', 30);                    // DosageForm
            $table->string('schedule', 20);                       // DrugSchedule
            $table->string('storage_note', 255)->nullable();      // "Store below 25 °C", "Refrigerate 2-8 °C"
            $table->timestamps();

            $table->index(['shop_id', 'brand_name']); // search by brand name
            $table->index(['shop_id', 'schedule']);   // list filter; checkout rules and the register later
            $table->index(['shop_id', 'dosage_form']);
        });

        Schema::create('product_generics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generic_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'generic_id']);
            $table->index('generic_id'); // every product containing a generic
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_generics');
        Schema::dropIfExists('drug_details');
        Schema::dropIfExists('generics');
    }
};
