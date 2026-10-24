<?php

namespace Tests\Feature\Pos;

use App\Filament\Pages\Dashboard;
use App\Models\User;
use Filament\Facades\Filament;
use Inertia\Testing\AssertableInertia as Assert;

/** Getting between the admin panel and the till, and keeping cashiers out of the panel. */
class AdminPosShortcutTest extends AdminTestCase
{
    public function test_owner_sees_the_open_pos_button_before_global_search_and_the_alt_p_shortcut(): void
    {
        $html = $this->actingAs($this->owner)->get(Dashboard::getUrl())->assertOk()->getContent();

        // Two renderings of the same button: icon-only with a tooltip on small screens, labelled from md up.
        $this->assertSame(2, substr_count($html, 'data-test="open-pos"'));
        $this->assertStringContainsString('href="'.route('pos.index').'"', $html);
        $this->assertStringContainsString('fi-labeled-from-md', $html);
        $this->assertStringContainsString("content: 'Open POS (Alt+P)'", $html);
        $this->assertStringContainsString('fi-color-primary', $html);
        $this->assertStringContainsString('data-icon="cash-register"', $html);
        $this->assertStringNotContainsString('target="_blank"', substr($html, strpos($html, 'data-test="open-pos"') - 400, 400));
        $this->assertStringContainsString("event.code === 'KeyP'", $html);

        if (str_contains($html, 'fi-global-search')) {
            $this->assertLessThan(strpos($html, 'fi-global-search'), strpos($html, 'data-test="open-pos"'));
        }
    }

    public function test_open_pos_is_the_first_sidebar_item_and_groups_follow_the_owners_order(): void
    {
        $this->actingAs($this->owner)->get(Dashboard::getUrl())->assertOk();

        $navigation = Filament::getNavigation();
        $items = collect($navigation)->flatMap(fn ($group) => $group->getItems())->map->getLabel()->values();
        $groups = collect($navigation)->map->getLabel()->filter()->values()->all();

        $this->assertSame(['Open POS', 'Dashboard'], $items->take(2)->all());
        $this->assertSame(['Catalog', 'Stock', 'Sales', 'Reports', 'Administration'], $groups);
    }

    public function test_admin_users_who_cannot_sell_do_not_get_the_pos_shortcuts(): void
    {
        $manager = User::factory()->create(['email' => 'manager@pos.test']);
        $manager->forceFill(['shop_id' => $this->shop->getKey()])->save();
        $manager->givePermissionTo(['admin.access', 'products.view']);

        $html = $this->actingAs($manager)->get(route('filament.admin.resources.products.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-test="open-pos"', $html);
        $this->assertStringNotContainsString("event.code === 'KeyP'", $html);
        $labels = collect(Filament::getNavigation())->flatMap(fn ($group) => $group->getItems())->map->getLabel();
        $this->assertNotContains('Open POS', $labels);
        $this->assertContains('Products', $labels);
    }

    public function test_a_cashier_never_sees_the_admin_link_or_the_panel(): void
    {
        $this->actingAs($this->cashier)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
            ->where('cashier.canAdmin', false));

        $this->get('/admin')->assertForbidden();
        $this->get(Dashboard::getUrl())->assertForbidden();
    }

    public function test_the_owner_keeps_the_admin_link_on_the_till(): void
    {
        $this->actingAs($this->owner)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
            ->where('cashier.canAdmin', true));
    }
}
