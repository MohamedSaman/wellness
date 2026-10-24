<?php

namespace Tests\Feature\Pos;

use App\Models\Location;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\MassAssignmentException;

class TenancyTest extends PosTestCase
{
    public function test_a_shop_only_sees_its_own_products(): void
    {
        $this->assertGreaterThan(0, Product::query()->count());

        $other = Shop::query()->create(['name' => 'Other Shop', 'code' => 'OTHER']);
        $this->useShop($other);

        $this->assertSame(0, Product::query()->count());

        Product::query()->create(['name' => 'Other shop product']);
        $this->assertSame(1, Product::query()->count());

        $this->useShop($this->shop);
        $this->assertSame(0, Product::query()->where('name', 'Other shop product')->count());
    }

    public function test_new_records_are_stamped_with_the_current_shop(): void
    {
        $product = Product::query()->create(['name' => 'Chain Lock']);

        $this->assertSame($this->shop->getKey(), (int) $product->shop_id);
    }

    public function test_shop_id_cannot_be_mass_assigned(): void
    {
        $other = Shop::query()->create(['name' => 'Other Shop', 'code' => 'OTHER']);

        $this->expectException(MassAssignmentException::class);

        Product::query()->create(['name' => 'Sneaky', 'shop_id' => $other->getKey()]);
    }

    public function test_records_cannot_be_created_without_a_shop(): void
    {
        $this->useShop(null);

        $this->expectException(\LogicException::class);

        Location::query()->create(['name' => 'Nowhere', 'code' => 'X']);
    }

    public function test_a_logged_in_user_without_a_shop_sees_nothing(): void
    {
        app(CurrentShop::class)->clear();

        $stranger = new User;
        $stranger->forceFill(['name' => 'Stranger', 'email' => 'stranger@example.com', 'password' => 'secret-password-123'])->save();

        $this->actingAs($stranger);

        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, PriceList::query()->count());
    }
}
