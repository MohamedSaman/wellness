<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use App\Services\BrandingService;
use App\Services\ThemeService;
use App\Support\BrandPalette;
use App\Support\ThemePresets;
use Filament\Facades\Filament;
use Inertia\Testing\AssertableInertia;

/**
 * The clinic's colour theme (feat/clinic-theme-dashboard): it starts on
 * Clinic Teal with no brand colour; the preset reaches the till and settings
 * pages (CSS variables, data-pos-theme on <html>) and the admin panel
 * (primary, success, danger and warning palettes, its variables in <head>
 * before anything is drawn); a brand colour still overrides primary. Only
 * the owner changes it (Settings → Appearance): a role, not a permission,
 * so a manager-like user, a non-owner given settings.manage and the cashier
 * are all refused.
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

    public function test_the_sidebars_item_states_reach_the_admin_panel_and_the_settings_pages(): void
    {
        $this->usePreset('ocean_blue');

        foreach (['light', 'dark'] as $mode) {
            $tokens = (array) ThemePresets::tokens('ocean_blue', $mode);

            $panel = (string) $this->actingAs($this->owner)->get(Filament::getUrl())->assertOk()->getContent();
            foreach (['sidebar_hover_bg', 'sidebar_active_bg', 'sidebar_active_hover_bg', 'sidebar_active_fg', 'sidebar_accent'] as $token) {
                $this->assertStringContainsString('--pos-'.str_replace('_', '-', $token).':'.$tokens[$token].';', $panel);
            }

            $settings = (string) $this->get(route('profile.edit'))->assertOk()->getContent();
            $this->assertStringContainsString('--sidebar-accent:'.$tokens['sidebar_hover_bg'].';--sidebar-accent-foreground:#ffffff;', $settings);
            $this->assertStringContainsString('--sidebar-muted:'.$tokens['sidebar_muted'].';--sidebar-active:'.$tokens['sidebar_active_bg'].';--sidebar-active-hover:'.$tokens['sidebar_active_hover_bg'].';--sidebar-active-foreground:#ffffff;--sidebar-indicator:'.$tokens['sidebar_accent'].';', $settings);
        }
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

    /** A user with the given permissions and no role. */
    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['shop_id' => $this->shop->getKey()]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    public function test_the_owner_changes_the_theme_and_it_clears_the_brand_colour(): void
    {
        $this->usePreset('clinic_teal', '#7c3aed');

        $this->actingAs($this->owner)
            ->patch(route('appearance.theme.update'), ['preset' => 'ocean_blue'])
            ->assertRedirect(route('appearance.edit'))
            ->assertSessionHasNoErrors();

        $shop = $this->shop->fresh();
        $this->assertSame('ocean_blue', $shop->setting('theme_preset'));
        $this->assertNull($shop->setting('brand_color'));
        // The shop's other settings are kept.
        $this->assertSame(90, $shop->setting('near_expiry_warning_days'));

        // Both panels wear it from the next page on.
        $this->assertStringContainsString('data-pos-theme="ocean_blue"', (string) $this->get(route('pos.index'))->getContent());
        $this->assertStringContainsString('posTheme="ocean_blue"', (string) $this->get(Filament::getUrl())->getContent());
    }

    public function test_only_the_owner_may_change_the_theme(): void
    {
        $manager = $this->userWith('admin.access', 'reports.view');
        $settingsManager = $this->userWith('admin.access', 'settings.manage');

        foreach (['cashier' => $this->cashier, 'manager' => $manager, 'settings.manage' => $settingsManager] as $who => $user) {
            $this->actingAs($user)
                ->patch(route('appearance.theme.update'), ['preset' => 'plum'])
                ->assertForbidden();
            $this->assertSame('clinic_teal', $this->shop->fresh()->setting('theme_preset'), $who);
        }
    }

    public function test_a_preset_that_does_not_exist_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->from(route('appearance.edit'))
            ->patch(route('appearance.theme.update'), ['preset' => 'neon'])
            ->assertSessionHasErrors('preset');

        $this->assertSame('clinic_teal', $this->shop->fresh()->setting('theme_preset'));
    }

    public function test_the_appearance_page_offers_the_presets_to_the_owner_only(): void
    {
        $this->actingAs($this->owner)->get(route('appearance.edit'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/Appearance')
                ->where('theme.current', 'clinic_teal')
                ->where('theme.hasBrandColor', false)
                ->has('theme.presets', 5)
                ->where('theme.presets.0.key', 'classic')
                ->where('theme.presets.0.css', '')
                ->where('theme.presets.1.key', 'clinic_teal')
                ->where('theme.presets.1.css', ThemeService::appCss('clinic_teal'))
                ->where('theme.presets.1.colors.light.primary', '#0f766e'));

        foreach ([$this->cashier, $this->userWith('admin.access', 'settings.manage')] as $user) {
            $this->actingAs($user)->get(route('appearance.edit'))->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->component('settings/Appearance')->where('theme', null));
        }
    }
}
