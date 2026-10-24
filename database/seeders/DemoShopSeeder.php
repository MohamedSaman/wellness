<?php

namespace Database\Seeders;

use App\Enums\AttributeType;
use App\Models\AttributeOption;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ProductAttribute;
use App\Models\Shop;
use App\Models\VehicleModel;
use App\Services\ProductCatalogService;
use App\Services\ShopSetupService;
use App\Support\CurrentShop;
use App\Support\Money;
use Database\Seeders\Concerns\SeedsDemoShop;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * LOCAL DEVELOPMENT ONLY: a demo moto-gear shop with helmets, jackets and
 * spare parts, an owner and a cashier. The retail profile's demo; skipped
 * under any other profile. Never run in production.
 *
 *   owner@pos.test   / password
 *   cashier@pos.test / password
 */
class DemoShopSeeder extends Seeder
{
    use SeedsDemoShop;

    public function run(CurrentShop $currentShop, ProductCatalogService $catalog, ShopSetupService $setup): void
    {
        $profile = $this->demoProfile();

        if ($profile === null) {
            return;
        }

        $shop = Shop::query()->create([
            'name' => 'Demo Moto Gear',
            'code' => 'DEMO',
            'business_type' => $profile->businessType(),
            'address' => '12 Main Street, Colombo',
            'phone' => '+94 11 000 0000',
            'currency' => 'LKR',
            'timezone' => 'Asia/Colombo',
            'receipt_footer' => 'Thank you! Exchanges within 7 days with receipt.',
        ]);

        $setup->provision($shop, $profile);
        $currentShop->set($shop);

        $owner = $this->user($shop, 'Shop Owner', 'owner@pos.test', 'owner');
        $this->user($shop, 'Demo Cashier', 'cashier@pos.test', 'cashier');

        // ---- Attributes (shop-defined, no code needed for new categories) ----
        $size = $this->attribute('Size', 'size', [['XS', 'XS'], ['S', 'S'], ['M', 'M'], ['L', 'L'], ['XL', 'XL'], ['XXL', 'XXL']]);
        $colour = $this->attribute('Colour', 'colour', [['Matte Black', 'MBK'], ['Gloss Black', 'GBK'], ['Red', 'RED'], ['White', 'WHT'], ['Blue', 'BLU']]);
        $helmetType = $this->attribute('Helmet Type', 'helmet_type', [['Full Face', 'FF'], ['Open Face', 'OF'], ['Off-Road', 'OR'], ['Modular', 'MOD']]);
        $material = $this->attribute('Material', 'material', [], AttributeType::Text);

        // ---- Categories ----
        $helmets = $this->category('Helmets');
        $helmets->productAttributes()->attach([
            $size->getKey() => ['is_variant_axis' => true, 'is_required' => true, 'sort_order' => 1],
            $colour->getKey() => ['is_variant_axis' => true, 'is_required' => true, 'sort_order' => 2],
            $helmetType->getKey() => ['is_variant_axis' => false, 'is_required' => true, 'sort_order' => 3],
        ]);

        $jackets = $this->category('Riding Jackets');
        $jackets->productAttributes()->attach([
            $size->getKey() => ['is_variant_axis' => true, 'is_required' => true, 'sort_order' => 1],
            $colour->getKey() => ['is_variant_axis' => true, 'is_required' => true, 'sort_order' => 2],
            $material->getKey() => ['is_variant_axis' => false, 'is_required' => false, 'sort_order' => 3],
        ]);

        $parts = $this->category('Spare Parts');
        $brakes = $this->category('Brake Parts', $parts);
        $electrical = $this->category('Electrical & Ignition', $parts);
        $oils = $this->category('Oils & Lubricants', $parts);

        $brand = fn (string $name) => Brand::query()->firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
        $opt = fn (ProductAttribute $attribute, string $value): AttributeOption => $attribute->options()->where('value', $value)->firstOrFail();

        // ---- Helmets: Size x Colour variants ----
        $catalog->create(
            ['name' => 'Vega Off-Road Helmet', 'category_id' => $helmets->getKey(), 'brand_id' => $brand('Vega')->getKey()],
            $this->matrix($size, ['M', 'L', 'XL'], $colour, ['Matte Black', 'Red'], price: Money::toMinor(18500), cost: Money::toMinor(13000), stock: 3),
            [$helmetType->getKey() => $opt($helmetType, 'Off-Road')->getKey()],
            $owner,
        );

        $catalog->create(
            ['name' => 'Studds Ninja Elite Helmet', 'category_id' => $helmets->getKey(), 'brand_id' => $brand('Studds')->getKey()],
            $this->matrix($size, ['M', 'L'], $colour, ['Gloss Black', 'White'], price: Money::toMinor(9800), cost: Money::toMinor(7000), stock: 4),
            [$helmetType->getKey() => $opt($helmetType, 'Full Face')->getKey()],
            $owner,
        );

        // ---- Jackets ----
        $catalog->create(
            ['name' => 'Rynox Stealth Evo Jacket', 'category_id' => $jackets->getKey(), 'brand_id' => $brand('Rynox')->getKey()],
            $this->matrix($size, ['M', 'L', 'XL'], $colour, ['Matte Black'], price: Money::toMinor(24900), cost: Money::toMinor(18500), stock: 2),
            [$material->getKey() => 'Polyester mesh with CE level 1 armour'],
            $owner,
        );

        // ---- Spare parts: single variant, part numbers, vehicle fitment ----
        $plug = $catalog->create(
            ['name' => 'NGK Spark Plug', 'part_number' => 'CPR8EA-9', 'oem_numbers' => ['31916-KRP-B01'],
                'category_id' => $electrical->getKey(), 'brand_id' => $brand('NGK')->getKey()],
            [['price' => Money::toMinor(1450), 'cost' => Money::toMinor(950), 'opening_stock' => 40, 'reorder_level' => 10, 'bin_location' => 'B1-04']],
            user: $owner,
        );
        $plug->fitments()->attach($this->vehicles(['Pulsar 150', 'Discover 125', 'CB Shine']));

        $pads = $catalog->create(
            ['name' => 'Front Brake Pad Set', 'part_number' => 'BP-PUL150-F',
                'category_id' => $brakes->getKey(), 'brand_id' => $brand('Bajaj Genuine')->getKey()],
            [['price' => Money::toMinor(2800), 'cost' => Money::toMinor(1900), 'opening_stock' => 15, 'reorder_level' => 5, 'bin_location' => 'C2-01']],
            user: $owner,
        );
        $pads->fitments()->attach($this->vehicles(['Pulsar 150']), ['year_from' => 2012]);

        $catalog->create(
            ['name' => 'Engine Oil 10W-30 (1L)', 'part_number' => 'MOTUL-3000-10W30-1L', 'unit' => 'bottle',
                'category_id' => $oils->getKey(), 'brand_id' => $brand('Motul')->getKey()],
            [['price' => Money::toMinor(3200), 'cost' => Money::toMinor(2450), 'opening_stock' => 24, 'reorder_level' => 6, 'bin_location' => 'D1-01']],
            user: $owner,
        );

        $currentShop->clear();
    }

    /** @param  list<array{0:string, 1:string}>  $options  [value, code] */
    private function attribute(string $name, string $code, array $options, AttributeType $type = AttributeType::Select): ProductAttribute
    {
        $attribute = ProductAttribute::query()->create(['name' => $name, 'code' => $code, 'type' => $type]);

        foreach ($options as $i => [$value, $optionCode]) {
            $attribute->options()->create(['value' => $value, 'code' => $optionCode, 'sort_order' => $i]);
        }

        return $attribute;
    }

    private function category(string $name, ?Category $parent = null): Category
    {
        return Category::query()->create([
            'name' => $name,
            'slug' => Str::slug($name),
            'parent_id' => $parent?->getKey(),
        ]);
    }

    /**
     * Every combination of two attributes' options (e.g. 3 sizes x 2 colours = 6 variants).
     *
     * @param  list<string>  $firstValues
     * @param  list<string>  $secondValues
     * @return list<array{options: list<AttributeOption>, price: int, cost: int, opening_stock: float, reorder_level: float}>
     */
    private function matrix(ProductAttribute $first, array $firstValues, ProductAttribute $second, array $secondValues, int $price, int $cost, float $stock): array
    {
        $firstOptions = $first->options()->whereIn('value', $firstValues)->get();
        $secondOptions = $second->options()->whereIn('value', $secondValues)->get();
        $variants = [];

        foreach ($firstOptions as $a) {
            foreach ($secondOptions as $b) {
                $variants[] = ['options' => [$a, $b], 'price' => $price, 'cost' => $cost, 'opening_stock' => $stock, 'reorder_level' => 1.0];
            }
        }

        return $variants;
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    private function vehicles(array $names): array
    {
        return array_values(VehicleModel::query()->whereIn('name', $names)->get()->map(fn (VehicleModel $vehicle): int => $vehicle->id)->all());
    }
}
