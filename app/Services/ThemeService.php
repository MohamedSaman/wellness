<?php

namespace App\Services;

use App\Models\Shop;
use App\Support\BrandPalette;
use App\Support\ThemePresets;

/**
 * The shop's colour theme (App\Support\ThemePresets) as each page needs it:
 * CSS variables for the till and settings pages (app.blade.php), palettes
 * and variables for the admin panel (AdminPanelProvider). One preset per
 * shop, the setting theme_preset; without one a pharmacy shop is Clinic Teal
 * and any other shop classic, which sets nothing, so a retail shop looks as
 * it always did. A brand colour (Shop settings → Branding) still overrides
 * the primary colour on top, as before presets.
 *
 * Nothing here is cached across requests: the shop is already loaded for
 * the request (CurrentShop) and the output is a few array lookups. The
 * shade scales, pure functions of a colour, are kept per process.
 */
class ThemeService
{
    /** @var array<string, BrandPalette> */
    private static array $palettes = [];

    public function __construct(private readonly BrandingService $branding) {}

    public function preset(?Shop $shop = null): string
    {
        $shop ??= $this->branding->shop();

        if ($shop === null) {
            return ThemePresets::CLASSIC;
        }

        $stored = $shop->setting('theme_preset');

        if (is_string($stored) && ThemePresets::exists($stored)) {
            return $stored;
        }

        return $shop->hasModule('pharmacy') ? ThemePresets::CLINIC_TEAL : ThemePresets::CLASSIC;
    }

    public function isThemed(?Shop $shop = null): bool
    {
        return $this->preset($shop) !== ThemePresets::CLASSIC;
    }

    /**
     * The till and settings pages' variables (resources/css/app.css names)
     * for a preset, light and dark. Empty for classic.
     *
     * @return array{light: array<string, string>, dark: array<string, string>}|null
     */
    public static function appVariables(string $preset): ?array
    {
        $light = ThemePresets::tokens($preset, 'light');
        $dark = ThemePresets::tokens($preset, 'dark');

        if ($light === null || $dark === null) {
            return null;
        }

        $map = fn (array $t, string $shadow): array => [
            '--background' => $t['background'],
            '--foreground' => $t['foreground'],
            '--card' => $t['card'],
            '--card-foreground' => $t['foreground'],
            '--popover' => $t['card'],
            '--popover-foreground' => $t['foreground'],
            '--primary' => $t['primary'],
            '--primary-foreground' => $t['primary_fg'],
            '--primary-hover' => $t['primary_hover'],
            '--primary-soft' => $t['primary_soft'],
            '--primary-text' => $t['primary_text'],
            '--secondary' => $t['primary_soft'],
            '--secondary-foreground' => $t['foreground'],
            '--muted' => $t['primary_soft'],
            '--muted-foreground' => $t['muted'],
            '--accent' => $t['primary_soft'],
            '--accent-foreground' => $t['foreground'],
            '--destructive' => $t['danger'],
            '--destructive-foreground' => $t['primary_fg'],
            '--danger-text' => $t['danger_text'],
            '--danger-soft' => $t['danger_soft'],
            '--warning' => $t['warning'],
            '--warning-text' => $t['warning_text'],
            '--warning-soft' => $t['warning_soft'],
            '--border' => $t['border'],
            '--input' => $t['border'],
            '--ring' => $t['primary'],
            '--sidebar-background' => $t['sidebar'],
            '--sidebar' => $t['sidebar'],
            '--sidebar-foreground' => $t['sidebar_fg'],
            '--sidebar-primary' => $t['sidebar_active'],
            '--sidebar-primary-foreground' => $t['sidebar_active_fg'],
            '--sidebar-accent' => $t['sidebar_active'],
            '--sidebar-accent-foreground' => $t['sidebar_active_fg'],
            '--sidebar-border' => $t['sidebar_border'],
            '--sidebar-ring' => $t['primary'],
            '--card-radius' => ThemePresets::RADIUS,
            '--card-shadow' => $shadow,
            '--app-font' => "'Inter', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji'",
        ];

        return [
            'light' => $map($light, '0 1px 2px rgb(15 31 28 / 0.04), 0 4px 16px rgb(15 31 28 / 0.05)'),
            'dark' => $map($dark, '0 1px 2px rgb(0 0 0 / 0.3)'),
        ];
    }

    /**
     * The admin panel's variables (resources/css/filament/admin/theme.css
     * reads them, only under html[data-pos-theme]).
     *
     * @return array{light: array<string, string>, dark: array<string, string>}|null
     */
    public static function panelVariables(string $preset): ?array
    {
        $light = ThemePresets::tokens($preset, 'light');
        $dark = ThemePresets::tokens($preset, 'dark');

        if ($light === null || $dark === null) {
            return null;
        }

        $map = fn (array $t, string $shadow): array => [
            ...array_combine(
                array_map(fn (string $token): string => '--pos-'.str_replace('_', '-', $token), array_keys($t)),
                array_values($t),
            ),
            '--pos-radius' => ThemePresets::RADIUS,
            '--pos-shadow' => $shadow,
        ];

        return [
            'light' => $map($light, '0 1px 2px rgb(15 31 28 / 0.04), 0 4px 16px rgb(15 31 28 / 0.05)'),
            'dark' => $map($dark, '0 1px 2px rgb(0 0 0 / 0.3)'),
        ];
    }

    /** The till and settings pages' style block (empty for classic), printed before the brand colour's. */
    public function appStyle(?Shop $shop = null): string
    {
        $css = self::appCss($this->preset($shop));

        return $css === '' ? '' : "<style>{$css}</style>";
    }

    /** A preset's rules for the till and settings pages; the Appearance page's live preview uses the same. */
    public static function appCss(string $preset): string
    {
        $vars = self::appVariables($preset);

        if ($vars === null) {
            return '';
        }

        // Hex values and fixed strings only (ThemePresets), so nothing here can break out of the block.
        // body too: app.css sets --font-sans on body as well as html, from --app-font.
        return 'html[data-pos-theme],html[data-pos-theme] body{'.self::declarations($vars['light']).'}'
            .'html[data-pos-theme].dark,html[data-pos-theme].dark body{'.self::declarations($vars['dark']).'}'
            .'html[data-pos-theme]{background-color:'.$vars['light']['--background'].'}'
            .'html[data-pos-theme].dark{background-color:'.$vars['dark']['--background'].'}';
    }

    /**
     * The Appearance page's choices: each preset's name, its rules (applied
     * to the page as a live preview) and the colours its swatches and
     * preview card are drawn in. Classic's are app.css's own, approximately.
     *
     * @return list<array{key: string, label: string, css: string, colors: array{light: array<string, string>, dark: array<string, string>}}>
     */
    public function pickerOptions(): array
    {
        $classic = [
            'light' => ['sidebar' => '#fafafa', 'sidebar_fg' => '#3f3f46', 'sidebar_active' => '#f0f0f0', 'sidebar_active_fg' => '#4d4d4d', 'primary' => '#171717', 'primary_fg' => '#fafafa', 'primary_soft' => '#f5f5f5', 'primary_text' => '#171717', 'background' => '#ffffff', 'card' => '#ffffff', 'border' => '#ededed', 'foreground' => '#0a0a0a', 'muted' => '#737373', 'danger' => '#ef4444', 'danger_text' => '#b91c1c', 'danger_soft' => '#fef2f2'],
            'dark' => ['sidebar' => '#18181b', 'sidebar_fg' => '#f4f4f5', 'sidebar_active' => '#292929', 'sidebar_active_fg' => '#f4f4f5', 'primary' => '#fafafa', 'primary_fg' => '#171717', 'primary_soft' => '#262626', 'primary_text' => '#fafafa', 'background' => '#0a0a0a', 'card' => '#0a0a0a', 'border' => '#262626', 'foreground' => '#fafafa', 'muted' => '#a3a3a3', 'danger' => '#ef4444', 'danger_text' => '#fca5a5', 'danger_soft' => '#3f1d1d'],
        ];

        $options = [];

        foreach (ThemePresets::options() as $key => $label) {
            $light = ThemePresets::tokens($key, 'light');
            $dark = ThemePresets::tokens($key, 'dark');

            $options[] = [
                'key' => $key,
                'label' => $label,
                'css' => self::appCss($key),
                'colors' => $light === null || $dark === null ? $classic : ['light' => $light, 'dark' => $dark],
            ];
        }

        return $options;
    }

    /**
     * The admin panel's head: the preset's name on <html> before anything is
     * drawn (so no flash of the old colours) and its variables. Empty for classic.
     */
    public function panelHead(?Shop $shop = null): string
    {
        $preset = $this->preset($shop);
        $vars = self::panelVariables($preset);

        if ($vars === null) {
            return '';
        }

        // The preset key is one of ThemePresets' own (lowercase letters and _).
        return '<script>document.documentElement.dataset.posTheme='.json_encode($preset).'</script>'
            .'<style>html[data-pos-theme]{'.self::declarations($vars['light']).'}'
            .'html[data-pos-theme].dark{'.self::declarations($vars['dark']).'}</style>';
    }

    /**
     * The admin panel's palettes: primary (and success, which is the primary
     * colour) and danger from the preset, warning too; a brand colour
     * replaces primary. Empty for classic without a brand colour, which
     * keeps the panel's amber.
     *
     * @return array<string, array<int, string>>
     */
    public function panelColors(?Shop $shop = null): array
    {
        $shop ??= $this->branding->shop();
        $tokens = ThemePresets::tokens($this->preset($shop));
        $colors = [];

        if ($tokens !== null) {
            $primary = self::palette($tokens['primary'])->forFilament();
            $colors = [
                'primary' => $primary,
                'success' => $primary,
                'danger' => self::palette($tokens['danger'])->forFilament(),
                'warning' => self::palette($tokens['warning'])->forFilament(),
            ];
        }

        $brand = $this->branding->palette($shop);

        if ($brand !== null) {
            $colors['primary'] = $brand->forFilament();
        }

        return $colors;
    }

    /** The browser's theme colour (address bar): the preset's sidebar, else null. */
    public function themeColor(?Shop $shop = null): ?string
    {
        return ThemePresets::tokens($this->preset($shop))['sidebar'] ?? null;
    }

    /** A preset colour's scale with the colour itself on shade 600, the panel's button shade. */
    public static function palette(string $hex): BrandPalette
    {
        return self::$palettes[$hex] ??= BrandPalette::from($hex, 600);
    }

    /** @param  array<string, string>  $vars */
    private static function declarations(array $vars): string
    {
        return implode('', array_map(fn (string $name, string $value): string => "{$name}:{$value};", array_keys($vars), $vars));
    }
}
