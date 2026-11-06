<?php

namespace Database\Seeders;

use App\Enums\AttributeType;
use App\Models\Category;
use App\Models\ProductAttribute;
use App\Models\Shop;
use App\Support\CurrentShop;
use Illuminate\Database\Seeder;

/**
 * END-TO-END TESTS ONLY (scripts/e2e-server.sh): a category with a variant
 * axis in the demo clinic. No clinic category has one, so a clinic product
 * can't otherwise have more than one variant, and the product form spec needs
 * two rows side by side.
 */
class E2eVariantAxisSeeder extends Seeder
{
    public function run(CurrentShop $currentShop): void
    {
        $shop = Shop::query()->where('code', 'CLINIC')->firstOrFail();

        $currentShop->runAs($shop, function (): void {
            $packSize = ProductAttribute::query()->create(['name' => 'Pack size', 'code' => 'e2e_pack_size', 'type' => AttributeType::Select]);
            $packSize->options()->create(['value' => 'Small', 'code' => 'S', 'sort_order' => 0]);
            $packSize->options()->create(['value' => 'Large', 'code' => 'L', 'sort_order' => 1]);

            Category::query()->create(['name' => 'E2E variants', 'slug' => 'e2e-variants'])
                ->productAttributes()
                ->attach([$packSize->getKey() => ['is_variant_axis' => true, 'is_required' => true, 'sort_order' => 1]]);
        });
    }
}
