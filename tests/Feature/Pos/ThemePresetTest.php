<?php

namespace Tests\Feature\Pos;

use App\Services\BrandingService;
use App\Services\ThemeService;
use App\Support\BrandPalette;
use App\Support\ThemePresets;
use Filament\Facades\Filament;

/**
 * The clinic's colour theme (feat/clinic-theme-dashboard): it starts on
 * Clinic Teal with no brand colour; the preset reaches the till and settings
 * pages (CSS variables, data-pos-theme on <html>) and the admin panel
 * (primary, success, danger and warning palettes, its variables in <head>
 * before anything is drawn); a brand colour still overrides primary.
 */
class ThemePresetTest extends PharmacyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    private function usePreset(?string $preset, ?string $brand = null): void
    {
        $this->shop->update(['settings' => [...($this->shop->settings ?? []), 'theme_preset' => $preset, 'brand_color' => $brand]]);
    }

    public function test_the_clinic_starts_on_clinic_teal_with_no_brand_colour(): void
    {
        $this->assertSame('clinic_teal', $this->shop->setting('theme_preset'));
        $this->assertNull($this->shop->setting('brand_color'));
        $this->assertNull(app(BrandingService::class)->color($this->shop));
        $this->assertSame('clinic_teal', app(ThemeService::class)->preset($this->shop));
    }

    public function test_a_pharmacy_shop_without_a_stored_preset_is_clinic_teal_and_a_bad_one_is_ignored(): void
    {
        $this->usePreset(null);
        $this->assertSame('clinic_teal', app(ThemeService::class)->preset($this->shop->fresh()));

        $this->usePreset('neon');
        $this->assertSame('clinic_teal', app(ThemeService::class)->preset($this->shop->fresh()));
    }

    public function test_the_preset_reaches_the_till_with_its_dark_variant_and_font(): void
    {
        $html = (string) $this->actingAs($this->owner)->get(route('pos.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-pos-theme="clinic_teal"', $html);
        $this->assertStringContainsString('html[data-pos-theme],html[data-pos-theme] body{--background:#eef4f3;--foreground:#0f1f1c;--card:#ffffff;', $html);
        $this->assertStringContainsString('--primary:#0f766e;--primary-foreground:#ffffff;--primary-hover:#115e59;', $html);
        $this->assertStringContainsString('--sidebar-background:#0f4c45;', $html);
        $this->assertStringContainsString('--destructive:#c81e2b;', $html);
        $this->assertStringContainsString("--app-font:'Inter',", $html);
        $this->assertStringContainsString('html[data-pos-theme].dark,html[data-pos-theme].dark body{--background:#0b1514;--foreground:#e6f2f0;--card:#12201e;', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="#0f4c45">', $html);
        // No brand colour: nothing overrides the preset.
        $this->assertStringNotContainsString('html:root{', $html);
    }

    public function test_the_preset_reaches_the_settings_pages_and_the_admin_panel(): void
    {
        $this->usePreset('plum');
        $tokens = (array) ThemePresets::tokens('plum');

        $settings = (string) $this->actingAs($this->owner)->get(route('profile.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('data-pos-theme="plum"', $settings);
        $this->assertStringContainsString('--primary:'.$tokens['primary'].';', $settings);

        $panel = (string) $this->get(Filament::getUrl())->assertOk()->getContent();
        // First thing in <head>: the preset on <html> and its variables.
        $this->assertMatchesRegularExpression('/<head>\s*<script>document\.documentElement\.dataset\.posTheme="plum"<\/script><style>html\[data-pos-theme\]\{--pos-sidebar:'.$tokens['sidebar'].';/', $panel);
        $primary = ThemeService::palette($tokens['primary'])->forFilament();
        $this->assertStringContainsString('--primary-600:'.$primary[600].';', $panel);
        $this->assertStringContainsString('--success-600:'.$primary[600].';', $panel);
        $this->assertStringContainsString('--danger-600:'.ThemeService::palette($tokens['danger'])->forFilament()[600].';', $panel);
        $this->assertStringContainsString('--warning-600:'.ThemeService::palette($tokens['warning'])->forFilament()[600].';', $panel);
    }

    public function test_a_brand_colour_still_overrides_the_presets_primary_and_nothing_else(): void
    {
        $this->usePreset('clinic_teal', '#7c3aed');
        $brand = BrandPalette::from('#7c3aed');

        $panel = (string) $this->actingAs($this->owner)->get(Filament::getUrl())->assertOk()->getContent();
        $this->assertStringContainsString('--primary-600:'.$brand->forFilament()[600].';', $panel);
        $this->assertStringContainsString('--danger-600:'.ThemeService::palette('#c81e2b')->forFilament()[600].';', $panel);

        // The brand colour's block comes after the preset's, so it wins at the same specificity.
        $till = (string) $this->get(route('pos.index'))->getContent();
        $this->assertGreaterThan(strpos($till, 'html[data-pos-theme],html[data-pos-theme] body{'), strpos($till, 'html:root{--primary:'.$brand->shades[600]));
    }

    public function test_classic_on_a_clinic_sets_nothing(): void
    {
        $this->usePreset('classic');

        $till = (string) $this->actingAs($this->owner)->get(route('pos.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-pos-theme', $till);
        $this->assertStringNotContainsString('posTheme', (string) $this->get(Filament::getUrl())->assertOk()->getContent());
    }

    public function test_the_sign_in_page_already_wears_the_theme(): void
    {
        $this->assertStringContainsString('data-pos-theme="clinic_teal"', (string) $this->get(route('login'))->assertOk()->getContent());
    }
}
