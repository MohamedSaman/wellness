<?php

namespace Tests\Feature\Pos;

use App\Services\ThemeService;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;

/** A retail shop is on the classic theme: no theme attribute, no variables, the panel's amber. It may choose a preset. */
class RetailThemeTest extends AdminTestCase
{
    public function test_retail_is_classic_and_its_pages_carry_nothing_of_the_theme(): void
    {
        $this->assertNull($this->shop->setting('theme_preset'));
        $this->assertSame('classic', app(ThemeService::class)->preset($this->shop));

        $till = (string) $this->actingAs($this->owner)->get(route('pos.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-pos-theme', $till);
        $this->assertStringNotContainsString('--app-font', $till);

        $panel = (string) $this->get(Filament::getUrl())->assertOk()->getContent();
        $this->assertStringNotContainsString('posTheme', $panel);
        $this->assertStringNotContainsString('--pos-', $panel);
        $this->assertStringContainsString('--primary-600:'.Color::Amber[600].';', $panel);
        $this->assertStringContainsString('--danger-600:'.Color::Red[600].';', $panel);
    }

    public function test_a_retail_shop_may_choose_a_preset(): void
    {
        $this->shop->update(['settings' => [...($this->shop->settings ?? []), 'theme_preset' => 'ocean_blue']]);

        $till = (string) $this->actingAs($this->owner)->get(route('pos.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-pos-theme="ocean_blue"', $till);
        $this->assertStringContainsString('posTheme="ocean_blue"', (string) $this->get(Filament::getUrl())->getContent());
    }
}
