<?php

namespace Tests\Feature\Pos;

use App\Support\BrandPalette;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;

/** A retail shop keeps the panel's amber and the till's own colours until a brand colour is set. */
class RetailBrandingTest extends AdminTestCase
{
    public function test_retail_is_unchanged_until_a_brand_colour_is_set(): void
    {
        $this->assertNull($this->shop->setting('brand_color'));

        $panel = (string) $this->actingAs($this->owner)->get(Filament::getUrl())->assertOk()->getContent();
        $this->assertStringContainsString('--primary-600:'.Color::Amber[600].';', $panel);

        $till = (string) $this->get(route('pos.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('html:root{', $till);
        $this->assertStringContainsString('<meta name="theme-color" content="#ff2d20">', $till);
        // The plain mark, not the starter kit's Laravel logo (BrandingService::iconUrl).
        $this->assertStringContainsString('<link rel="icon" href="/favicon.svg">', $till);
    }

    public function test_a_retail_shop_that_sets_a_brand_colour_gets_it_in_the_panel_and_the_till(): void
    {
        // Set before the first request: the panel works its colours out once per request.
        $this->shop->update(['settings' => [...($this->shop->settings ?? []), 'brand_color' => '#1e3a8a']]);
        $palette = BrandPalette::from('#1e3a8a');

        $panel = (string) $this->actingAs($this->owner)->get(Filament::getUrl())->assertOk()->getContent();
        $this->assertStringContainsString('--primary-600:'.$palette->forFilament()[600].';', $panel);
        $this->assertStringContainsString('--warning-600:'.Color::Amber[600].';', $panel);
        $this->assertStringContainsString('html:root{--primary:'.$palette->shades[600].';', (string) $this->get(route('pos.index'))->getContent());
    }
}
