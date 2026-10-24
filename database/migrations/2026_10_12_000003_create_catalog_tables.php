<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['shop_id', 'slug']);
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();

            $table->unique(['shop_id', 'slug']);
        });

        // Configurable attributes: Size, Colour, Helmet Type, Material ...
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code', 50);                // "size", "colour"
            $table->string('type', 20)->default('select');
            $table->string('unit', 20)->nullable();    // "g", "mm"
            $table->timestamps();

            $table->unique(['shop_id', 'code']);
        });

        Schema::create('attribute_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_attribute_id')->constrained()->cascadeOnDelete();
            $table->string('value');
            $table->string('code', 50)->nullable();    // short code used in SKUs: "M", "BLK"
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_attribute_id', 'value']);
        });

        Schema::create('category_attribute', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_attribute_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_variant_axis')->default(false); // creates variants (Size, Colour)
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->unique(['category_id', 'product_attribute_id']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('part_number', 100)->nullable();  // spare parts
            $table->json('oem_numbers')->nullable();          // alternative / OEM numbers
            $table->string('unit', 20)->default('pcs');       // pcs, set, pair, litre, m
            $table->boolean('track_stock')->default(true);    // false for services (fitting, labour)
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['shop_id', 'name']);
            $table->index(['shop_id', 'part_number']);
            $table->index(['shop_id', 'category_id']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('name')->nullable();               // "M / Matte Black"; null = single variant
            $table->string('sku', 100);
            $table->string('barcode', 100)->nullable();
            $table->unsignedBigInteger('cost_price')->default(0); // minor units
            $table->decimal('reorder_level', 14, 3)->default(0);
            $table->string('bin_location', 50)->nullable();   // shelf / rack, e.g. "A3-02"
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['shop_id', 'sku']);
            $table->unique(['shop_id', 'barcode']);
        });

        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_attribute_id')->constrained()->restrictOnDelete();
            $table->foreignId('attribute_option_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('value')->nullable();               // for text / number attributes
            $table->timestamps();

            $table->unique(['product_id', 'product_attribute_id']);
        });

        Schema::create('variant_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_attribute_id')->constrained()->restrictOnDelete();
            $table->foreignId('attribute_option_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('value')->nullable();
            $table->timestamps();

            $table->unique(['product_variant_id', 'product_attribute_id'], 'variant_attr_unique');
        });

        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->string('name');                            // Retail, Wholesale
            $table->string('code', 30);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['shop_id', 'code']);
        });

        Schema::create('variant_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_list_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('price');               // minor units
            $table->decimal('min_quantity', 14, 3)->default(1); // wholesale tiers later
            $table->timestamps();

            $table->unique(['product_variant_id', 'price_list_id', 'min_quantity'], 'variant_price_unique');
        });

        // Shared vehicle reference data (not per shop).
        Schema::create('vehicle_makes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('vehicle_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_make_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('type', 30)->default('motorcycle');
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->timestamps();

            $table->unique(['vehicle_make_id', 'name']);
        });

        Schema::create('product_fitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_model_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'vehicle_model_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'product_fitments', 'vehicle_models', 'vehicle_makes', 'variant_prices', 'price_lists',
            'variant_attribute_values', 'product_attribute_values', 'product_variants', 'products',
            'category_attribute', 'attribute_options', 'product_attributes', 'brands', 'categories',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
