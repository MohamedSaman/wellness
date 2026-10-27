<?php

namespace App\Support;

/**
 * The shop's colour theme (Settings → Appearance → Clinic colour theme): a
 * preset of colour tokens, one source of truth for the till and settings
 * pages (resources/css/app.css variables) and the admin panel (Filament's
 * primary and danger palettes plus resources/css/filament/admin/theme.css).
 *
 * "classic" is the look from before presets: it sets nothing, so the pages
 * keep app.css's own colours and the panel its amber. The others set every
 * token for light and dark mode; each text colour reads on the surfaces it
 * is drawn on (WCAG AA, 4.5:1; see contrastPairs(), checked by a test).
 * Red is for alerts only: success is the primary colour.
 *
 * Tokens: the sidebar, its text and muted text (group labels); primary (buttons, white text),
 * its hover, its soft tint and primary_text (links and figures on a card);
 * the page background, cards and their border; text and muted text;
 * danger and warning as a fill (white text), as text, and as a soft tint.
 *
 * The sidebar's item states are worked out from the sidebar and primary
 * colours (sidebarStates()), so a new preset gets them without listing
 * them; a preset may still set any of them itself.
 */
final class ThemePresets
{
    public const CLASSIC = 'classic';

    public const CLINIC_TEAL = 'clinic_teal';

    /** Every themed preset: a soft shadow under cards and 14px corners. */
    public const RADIUS = '14px';

    /** The minimum contrast for an icon, a focus ring or the active item's bar (WCAG AA, non-text). */
    public const AA_UI = 3.0;

    /** @var array<string, array<string, string>> key.mode => tokens, worked out once per process */
    private static array $tokens = [];

    /** @var array<string, array{label: string, light: array<string, string>, dark: array<string, string>}> */
    private const PRESETS = [
        'clinic_teal' => [
            'label' => 'Clinic Teal',
            'light' => [
                'sidebar' => '#0f4c45', 'sidebar_fg' => '#e6f2f0', 'sidebar_muted' => '#a9cbc5', 'sidebar_border' => '#1d5d55',
                'primary' => '#0f766e', 'primary_hover' => '#115e59', 'primary_fg' => '#ffffff',
                'primary_soft' => '#dcebe8', 'primary_text' => '#0d6b64',
                'background' => '#eef4f3', 'card' => '#ffffff', 'border' => '#e2ecea',
                'foreground' => '#0f1f1c', 'muted' => '#5b6b68',
                'danger' => '#c81e2b', 'danger_text' => '#c81e2b', 'danger_soft' => '#fce8ea',
                'warning' => '#b45309', 'warning_text' => '#b45309', 'warning_soft' => '#fef3c7',
            ],
            'dark' => [
                'sidebar' => '#0b2f2b', 'sidebar_fg' => '#e6f2f0', 'sidebar_muted' => '#93b5af', 'sidebar_border' => '#17403b',
                'primary' => '#0f766e', 'primary_hover' => '#115e59', 'primary_fg' => '#ffffff',
                'primary_soft' => '#163a36', 'primary_text' => '#5fd4c4',
                'background' => '#0b1514', 'card' => '#12201e', 'border' => '#1f302d',
                'foreground' => '#e6f2f0', 'muted' => '#9fb4b0',
                'danger' => '#c81e2b', 'danger_text' => '#fca5a5', 'danger_soft' => '#3b1519',
                'warning' => '#b45309', 'warning_text' => '#fcd34d', 'warning_soft' => '#3a2a0c',
            ],
        ],
        'ocean_blue' => [
            'label' => 'Ocean Blue',
            'light' => [
                'sidebar' => '#0c3b5e', 'sidebar_fg' => '#e6eff7', 'sidebar_muted' => '#a7c0d6', 'sidebar_border' => '#1a4c72',
                'primary' => '#1d5fae', 'primary_hover' => '#174c8c', 'primary_fg' => '#ffffff',
                'primary_soft' => '#dde9f6', 'primary_text' => '#1d5fae',
                'background' => '#eef3f8', 'card' => '#ffffff', 'border' => '#dfe7f0',
                'foreground' => '#0f1a26', 'muted' => '#55657a',
                'danger' => '#c81e2b', 'danger_text' => '#c81e2b', 'danger_soft' => '#fce8ea',
                'warning' => '#b45309', 'warning_text' => '#b45309', 'warning_soft' => '#fef3c7',
            ],
            'dark' => [
                'sidebar' => '#0a2540', 'sidebar_fg' => '#e6eff7', 'sidebar_muted' => '#93abc4', 'sidebar_border' => '#163552',
                'primary' => '#1d5fae', 'primary_hover' => '#174c8c', 'primary_fg' => '#ffffff',
                'primary_soft' => '#16273d', 'primary_text' => '#8dbdf0',
                'background' => '#0a111a', 'card' => '#121c28', 'border' => '#1e2a3a',
                'foreground' => '#e6eff7', 'muted' => '#9eadc0',
                'danger' => '#c81e2b', 'danger_text' => '#fca5a5', 'danger_soft' => '#3b1519',
                'warning' => '#b45309', 'warning_text' => '#fcd34d', 'warning_soft' => '#3a2a0c',
            ],
        ],
        'plum' => [
            'label' => 'Plum',
            'light' => [
                'sidebar' => '#4a1d45', 'sidebar_fg' => '#f5e9f3', 'sidebar_muted' => '#d2b4cd', 'sidebar_border' => '#5c2c57',
                'primary' => '#842d7a', 'primary_hover' => '#6b2463', 'primary_fg' => '#ffffff',
                'primary_soft' => '#f2e3ef', 'primary_text' => '#842d7a',
                'background' => '#f6f0f5', 'card' => '#ffffff', 'border' => '#eadde7',
                'foreground' => '#1f1220', 'muted' => '#6b5a68',
                'danger' => '#c81e2b', 'danger_text' => '#c81e2b', 'danger_soft' => '#fce8ea',
                'warning' => '#b45309', 'warning_text' => '#b45309', 'warning_soft' => '#fef3c7',
            ],
            'dark' => [
                'sidebar' => '#2e1230', 'sidebar_fg' => '#f5e9f3', 'sidebar_muted' => '#c3a6be', 'sidebar_border' => '#41203f',
                'primary' => '#842d7a', 'primary_hover' => '#6b2463', 'primary_fg' => '#ffffff',
                'primary_soft' => '#2f1a2d', 'primary_text' => '#e5a9db',
                'background' => '#140c14', 'card' => '#20141f', 'border' => '#33222f',
                'foreground' => '#f5e9f3', 'muted' => '#bba7b7',
                'danger' => '#c81e2b', 'danger_text' => '#fca5a5', 'danger_soft' => '#3b1519',
                'warning' => '#b45309', 'warning_text' => '#fcd34d', 'warning_soft' => '#3a2a0c',
            ],
        ],
        'slate' => [
            'label' => 'Slate',
            'light' => [
                'sidebar' => '#1e293b', 'sidebar_fg' => '#e2e8f0', 'sidebar_muted' => '#a3b0c2', 'sidebar_border' => '#2c3a50',
                'primary' => '#334155', 'primary_hover' => '#1e293b', 'primary_fg' => '#ffffff',
                'primary_soft' => '#e2e8f0', 'primary_text' => '#334155',
                'background' => '#f1f5f9', 'card' => '#ffffff', 'border' => '#e2e8f0',
                'foreground' => '#0f172a', 'muted' => '#475569',
                'danger' => '#c81e2b', 'danger_text' => '#c81e2b', 'danger_soft' => '#fce8ea',
                'warning' => '#b45309', 'warning_text' => '#b45309', 'warning_soft' => '#fef3c7',
            ],
            'dark' => [
                'sidebar' => '#0f172a', 'sidebar_fg' => '#e2e8f0', 'sidebar_muted' => '#94a3b8', 'sidebar_border' => '#1e293b',
                'primary' => '#475569', 'primary_hover' => '#334155', 'primary_fg' => '#ffffff',
                'primary_soft' => '#1e293b', 'primary_text' => '#cbd5e1',
                'background' => '#0b1120', 'card' => '#131c2e', 'border' => '#243045',
                'foreground' => '#e2e8f0', 'muted' => '#a0aec0',
                'danger' => '#c81e2b', 'danger_text' => '#fca5a5', 'danger_soft' => '#3b1519',
                'warning' => '#b45309', 'warning_text' => '#fcd34d', 'warning_soft' => '#3a2a0c',
            ],
        ],
    ];

    /** @return array<string, string> key => label, classic first */
    public static function options(): array
    {
        return [self::CLASSIC => 'Classic', ...array_map(fn (array $preset): string => $preset['label'], self::PRESETS)];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::options());
    }

    public static function exists(?string $key): bool
    {
        return is_string($key) && array_key_exists($key, self::options());
    }

    /**
     * A themed preset's tokens for one mode; null for classic (or anything
     * unknown), which sets nothing.
     *
     * @return array<string, string>|null
     */
    public static function tokens(string $key, string $mode = 'light'): ?array
    {
        $mode = $mode === 'dark' ? 'dark' : 'light';
        $tokens = self::PRESETS[$key][$mode] ?? null;

        if ($tokens === null) {
            return null;
        }

        // The preset's own value wins over a worked-out one.
        return self::$tokens["{$key}.{$mode}"] ??= $tokens + self::sidebarStates($tokens);
    }

    /**
     * A sidebar item's states, from the sidebar and primary colours: hover a
     * faint white wash over the sidebar (8%), active a stronger one (14%,
     * 18% when hovered too), white text on both, and the accent (the active
     * item's left bar and the keyboard focus ring) a light, bright tint of
     * the primary's hue.
     *
     * @param  array<string, string>  $tokens
     * @return array<string, string>
     */
    private static function sidebarStates(array $tokens): array
    {
        [, $chroma, $hue] = BrandPalette::toOklch($tokens['primary']);

        return [
            'sidebar_hover_bg' => self::overWhite($tokens['sidebar'], 0.08),
            'sidebar_active_bg' => self::overWhite($tokens['sidebar'], 0.14),
            'sidebar_active_hover_bg' => self::overWhite($tokens['sidebar'], 0.18),
            'sidebar_active_fg' => '#ffffff',
            'sidebar_accent' => BrandPalette::toHex(0.86, min(0.14, $chroma * 1.5), $hue),
        ];
    }

    /** White at $alpha over a colour, as one #rrggbb (what rgb(255 255 255 / $alpha) on top of it shows). */
    private static function overWhite(string $hex, float $alpha): string
    {
        return '#'.implode('', array_map(
            fn (string $channel): string => sprintf('%02x', (int) round(255 * $alpha + hexdec($channel) * (1 - $alpha))),
            str_split(substr($hex, 1), 2),
        ));
    }

    /**
     * The text-on-surface pairs a preset draws, as [text token, surface token]:
     * each must reach WCAG AA (4.5:1) in both modes.
     *
     * @return list<array{string, string}>
     */
    public static function contrastPairs(): array
    {
        return [
            ['foreground', 'background'], ['foreground', 'card'], ['foreground', 'primary_soft'],
            ['muted', 'background'], ['muted', 'card'],
            ['sidebar_fg', 'sidebar'], ['sidebar_muted', 'sidebar'], ['sidebar', 'sidebar_fg'],
            ['sidebar_active_fg', 'sidebar_hover_bg'], ['sidebar_active_fg', 'sidebar_active_bg'], ['sidebar_active_fg', 'sidebar_active_hover_bg'],
            ['primary_fg', 'primary'], ['primary_fg', 'primary_hover'],
            ['primary_text', 'card'], ['primary_text', 'background'], ['primary_text', 'primary_soft'],
            ['primary_fg', 'danger'], ['danger_text', 'card'], ['danger_text', 'danger_soft'],
            ['primary_fg', 'warning'], ['warning_text', 'card'], ['warning_text', 'warning_soft'],
        ];
    }

    /**
     * The sidebar's icons, focus ring and active bar on the surfaces they are
     * drawn on, as [colour token, surface token]: each must reach 3:1 (AA_UI)
     * in both modes. Icons take their item's text colour.
     *
     * @return list<array{string, string}>
     */
    public static function uiContrastPairs(): array
    {
        return [
            ['sidebar_fg', 'sidebar'], ['sidebar_muted', 'sidebar'],
            ['sidebar_active_fg', 'sidebar_hover_bg'], ['sidebar_active_fg', 'sidebar_active_bg'], ['sidebar_active_fg', 'sidebar_active_hover_bg'],
            ['sidebar_accent', 'sidebar_active_bg'], ['sidebar_accent', 'sidebar_active_hover_bg'],
            ['sidebar_accent', 'sidebar'], ['sidebar_accent', 'sidebar_hover_bg'],
        ];
    }
}
