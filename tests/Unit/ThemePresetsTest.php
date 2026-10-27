<?php

namespace Tests\Unit;

use App\Services\ThemeService;
use App\Support\BrandPalette;
use App\Support\ThemePresets;
use PHPUnit\Framework\TestCase;

/**
 * The colour themes (App\Support\ThemePresets): every text colour reads on
 * every surface it is drawn on, in light and dark mode (WCAG AA, 4.5:1);
 * every themed preset sets the same tokens; Clinic Teal is the clinic's
 * agreed colours; classic sets nothing.
 */
class ThemePresetsTest extends TestCase
{
    public function test_every_preset_passes_wcag_aa_for_text_in_light_and_dark_mode(): void
    {
        foreach (ThemePresets::keys() as $preset) {
            foreach (['light', 'dark'] as $mode) {
                $tokens = ThemePresets::tokens($preset, $mode);

                if ($tokens === null) {
                    continue;
                }

                foreach (ThemePresets::contrastPairs() as [$text, $surface]) {
                    $ratio = BrandPalette::contrast($tokens[$text], $tokens[$surface]);
                    $this->assertGreaterThanOrEqual(BrandPalette::AA_TEXT, $ratio, "{$preset} {$mode}: {$text} on {$surface} is ".round($ratio, 2).':1');
                }
            }
        }
    }

    public function test_every_presets_sidebar_icons_focus_ring_and_active_bar_reach_3_to_1_in_light_and_dark_mode(): void
    {
        foreach (array_diff(ThemePresets::keys(), [ThemePresets::CLASSIC]) as $preset) {
            foreach (['light', 'dark'] as $mode) {
                $tokens = (array) ThemePresets::tokens($preset, $mode);

                foreach (ThemePresets::uiContrastPairs() as [$colour, $surface]) {
                    $ratio = BrandPalette::contrast($tokens[$colour], $tokens[$surface]);
                    $this->assertGreaterThanOrEqual(ThemePresets::AA_UI, $ratio, "{$preset} {$mode}: {$colour} on {$surface} is ".round($ratio, 2).':1');
                }
            }
        }
    }

    public function test_every_presets_sidebar_states_are_white_washes_over_its_sidebar(): void
    {
        foreach (array_diff(ThemePresets::keys(), [ThemePresets::CLASSIC]) as $preset) {
            foreach (['light', 'dark'] as $mode) {
                $tokens = (array) ThemePresets::tokens($preset, $mode);
                $lightness = fn (string $token): float => BrandPalette::toOklch($tokens[$token])[0];

                // Each state is lighter than the one before, so hover, active and active + hover all look different.
                $this->assertLessThan($lightness('sidebar_hover_bg'), $lightness('sidebar'), "{$preset} {$mode}");
                $this->assertLessThan($lightness('sidebar_active_bg'), $lightness('sidebar_hover_bg'), "{$preset} {$mode}");
                $this->assertLessThan($lightness('sidebar_active_hover_bg'), $lightness('sidebar_active_bg'), "{$preset} {$mode}");
                $this->assertSame('#ffffff', $tokens['sidebar_active_fg'], "{$preset} {$mode}");

                // The accent is a light tint of the primary's own hue.
                $this->assertGreaterThan(0.8, $lightness('sidebar_accent'), "{$preset} {$mode}");
            }
        }

        $light = (array) ThemePresets::tokens(ThemePresets::CLINIC_TEAL, 'light');
        $dark = (array) ThemePresets::tokens(ThemePresets::CLINIC_TEAL, 'dark');
        $this->assertSame(['#225a54', '#31655f', '#3a6c66', '#53ecde'], [$light['sidebar_hover_bg'], $light['sidebar_active_bg'], $light['sidebar_active_hover_bg'], $light['sidebar_accent']]);
        $this->assertSame(['#1f403c', '#2d4c49', '#375451', '#53ecde'], [$dark['sidebar_hover_bg'], $dark['sidebar_active_bg'], $dark['sidebar_active_hover_bg'], $dark['sidebar_accent']]);
    }

    public function test_every_themed_preset_sets_the_same_tokens_as_hex(): void
    {
        $expected = array_keys((array) ThemePresets::tokens(ThemePresets::CLINIC_TEAL));
        $this->assertSame(['classic', 'clinic_teal', 'ocean_blue', 'plum', 'slate'], ThemePresets::keys());

        foreach (array_diff(ThemePresets::keys(), [ThemePresets::CLASSIC]) as $preset) {
            foreach (['light', 'dark'] as $mode) {
                $tokens = (array) ThemePresets::tokens($preset, $mode);
                $this->assertSame($expected, array_keys($tokens), "{$preset} {$mode}");

                foreach ($tokens as $token => $hex) {
                    $this->assertTrue(BrandPalette::isValid($hex), "{$preset} {$mode} {$token}");
                }
            }
        }
    }

    public function test_clinic_teal_is_the_clinics_colours(): void
    {
        $light = (array) ThemePresets::tokens(ThemePresets::CLINIC_TEAL, 'light');
        $dark = (array) ThemePresets::tokens(ThemePresets::CLINIC_TEAL, 'dark');

        $this->assertSame(
            ['#0f4c45', '#e6f2f0', '#31655f', '#ffffff', '#0f766e', '#115e59', '#dcebe8', '#eef4f3', '#ffffff', '#e2ecea', '#0f1f1c', '#5b6b68', '#c81e2b', '#fce8ea', '#b45309', '#fef3c7'],
            [$light['sidebar'], $light['sidebar_fg'], $light['sidebar_active_bg'], $light['sidebar_active_fg'], $light['primary'], $light['primary_hover'], $light['primary_soft'], $light['background'], $light['card'], $light['border'], $light['foreground'], $light['muted'], $light['danger'], $light['danger_soft'], $light['warning'], $light['warning_soft']],
        );
        $this->assertSame(
            ['#0b2f2b', '#0b1514', '#12201e', '#1f302d', '#0f766e'],
            [$dark['sidebar'], $dark['background'], $dark['card'], $dark['border'], $dark['primary']],
        );
        $this->assertSame('14px', ThemePresets::RADIUS);
    }

    public function test_classic_sets_nothing_and_an_unknown_preset_is_not_one(): void
    {
        $this->assertNull(ThemePresets::tokens(ThemePresets::CLASSIC));
        $this->assertNull(ThemeService::appVariables(ThemePresets::CLASSIC));
        $this->assertNull(ThemeService::panelVariables(ThemePresets::CLASSIC));
        $this->assertFalse(ThemePresets::exists('neon'));
        $this->assertFalse(ThemePresets::exists(null));
        $this->assertNull(ThemePresets::tokens('neon'));
    }

    public function test_a_preset_colour_sits_on_the_panels_button_shade_in_an_even_scale(): void
    {
        foreach (array_diff(ThemePresets::keys(), [ThemePresets::CLASSIC]) as $preset) {
            $tokens = (array) ThemePresets::tokens($preset);

            foreach (['primary', 'danger', 'warning'] as $token) {
                $palette = ThemeService::palette($tokens[$token]);
                $this->assertSame($tokens[$token], $palette->shades[600], "{$preset} {$token}");

                $lightness = array_map(fn (string $hex): float => BrandPalette::toOklch($hex)[0], array_values($palette->shades));
                for ($i = 1; $i < count($lightness); $i++) {
                    $this->assertLessThan($lightness[$i - 1], $lightness[$i], "{$preset} {$token} shade ".BrandPalette::SHADES[$i]);
                }
            }
        }
    }
}
