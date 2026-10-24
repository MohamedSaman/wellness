<?php

namespace Tests\Feature\Pos;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SaleItem;
use App\Models\VariantPrice;
use App\Models\VariantUnit;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\ProductCatalogService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Units on a retail shop (pharmacy off): every item is one base unit of
 * factor 1, decimal quantities still sell, and nothing a cashier sees changes.
 */
class RetailUnitsTest extends PosTestCase
{
    public function test_every_retail_variant_has_one_base_unit_named_after_its_product_unit_and_no_packs(): void
    {
        $variants = ProductVariant::query()->withTrashed()->with(['product', 'units'])->get();

        $this->assertNotEmpty($variants);

        foreach ($variants as $variant) {
            $this->assertCount(1, $variant->units, $variant->sku);

            /** @var VariantUnit $base */
            $base = $variant->units->first();
            $this->assertTrue($base->is_base);
            $this->assertSame(1.0, (float) $base->factor);
            $this->assertSame($variant->product->unit, $base->name);
            $this->assertTrue($variant->canSellUnit($base));
            $this->assertFalse($variant->product->whole_units_only);
        }

        $this->assertFalse(VariantPrice::query()->whereNotIn('variant_unit_id', VariantUnit::query()->where('is_base', true)->select('id'))->exists());
    }

    public function test_a_retail_sale_is_recorded_in_the_base_unit_exactly_as_before(): void
    {
        $plug = $this->variant('CPR8EA-9');
        $before = $this->stockOf($plug);

        $sale = app(CheckoutService::class)->checkout($this->cart([new CartLine($plug->getKey(), 2)]));
        $line = $sale->items->first();

        $this->assertSame(2.0, (float) $line->quantity);
        $this->assertSame(2.0, (float) $line->base_quantity);
        $this->assertSame(1.0, (float) $line->unit_factor);
        $this->assertSame($plug->product->unit, $line->unit_name);
        $this->assertSame(trim($plug->product->name.' '.($plug->name ?? '')), $line->description);
        $this->assertSame($before - 2, $this->stockOf($plug));
    }

    public function test_retail_keeps_decimal_quantities_and_decimal_pack_factors(): void
    {
        $oil = app(ProductCatalogService::class)->create(
            ['name' => 'Engine oil 10W-40 (loose)', 'unit' => 'litre'],
            [['price' => 250_000, 'opening_stock' => 100, 'packs' => [['name' => 'Drum', 'factor' => 20.5, 'price' => 4_800_000]]]],
            user: $this->owner,
        )->variants()->firstOrFail();

        $drum = $oil->units()->where('name', 'Drum')->firstOrFail();

        $sale = app(CheckoutService::class)->checkout($this->cart([
            new CartLine($oil->getKey(), 1.5),
            new CartLine($oil->getKey(), 1, unitId: $drum->getKey()),
        ]));

        $this->assertSame(375_000 + 4_800_000, $sale->grand_total);
        $this->assertSame(100 - 1.5 - 20.5, $this->stockOf($oil));
    }

    public function test_the_unit_migrations_backfill_existing_retail_data(): void
    {
        $plug = $this->variant('CPR8EA-9');
        app(CheckoutService::class)->checkout($this->cart([new CartLine($plug->getKey(), 3)]));
        $variants = ProductVariant::query()->withTrashed()->count();
        $prices = VariantPrice::query()->count();

        // Back to the schema before units, as on the live retail install, with the same data in it
        // (and back through anything migrated after units, so later branches keep this test).
        $steps = DB::table('migrations')->where('migration', '>=', '2026_10_18_000001_create_variant_units_table')->count();
        Artisan::call('migrate:rollback', ['--step' => $steps]);
        $this->assertFalse(Schema::hasTable('variant_units'));
        $this->assertFalse(Schema::hasColumn('sale_items', 'base_quantity'));

        Artisan::call('migrate');

        $this->assertSame($variants, DB::table('variant_units')->where('is_base', true)->count());
        $this->assertSame($variants, DB::table('variant_units')->count());
        $this->assertSame($prices, DB::table('variant_prices')->whereNotNull('variant_unit_id')->count());
        $this->assertFalse(Product::query()->where('whole_units_only', true)->exists());

        $line = SaleItem::query()->where('product_variant_id', $plug->getKey())->firstOrFail();
        $this->assertSame(3.0, (float) $line->base_quantity);
        $this->assertSame(1.0, (float) $line->unit_factor);
        $this->assertNull($line->variant_unit_id);

        $plug = $plug->fresh();
        $this->assertSame($plug->product->unit, $plug->baseUnit()->firstOrFail()->name);
        $this->assertNotNull($plug->priceOn());
    }
}
