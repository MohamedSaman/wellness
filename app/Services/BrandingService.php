<?php

namespace App\Services;

use App\Models\Shop;
use App\Support\BrandPalette;
use App\Support\CurrentShop;
use Illuminate\Support\Facades\Storage;

/**
 * The shop's branding (Shop settings → Branding): its logo (also printed on
 * receipts), favicon and brand colour. The colour is the admin panel's
 * primary colour and the till's accent, as a full shade scale readable in
 * light and dark mode (BrandPalette). Status colours (danger, warning,
 * success) are never the brand's. No brand colour set: the panel keeps its
 * amber and the till its own colours, as before.
 */
class BrandingService
{
    public function __construct(private readonly CurrentShop $currentShop) {}

    /**
     * The shop whose branding a page shows: the current shop, or on pages
     * before login (one install per client) the install's only shop.
     */
    public function shop(): ?Shop
    {
        $shop = $this->currentShop->get();

        if ($shop !== null) {
            return $shop;
        }

        $shops = Shop::query()->where('is_active', true)->limit(2)->get();

        return $shops->count() === 1 ? $shops->first() : null;
    }

    public function color(?Shop $shop = null): ?string
    {
        $color = ($shop ?? $this->shop())?->setting('brand_color');

        return BrandPalette::isValid($color) ? strtolower((string) $color) : null;
    }

    public function palette(?Shop $shop = null): ?BrandPalette
    {
        $color = $this->color($shop);

        return $color === null ? null : BrandPalette::from($color);
    }

    public function faviconUrl(?Shop $shop = null): ?string
    {
        $path = ($shop ?? $this->shop())?->setting('favicon_path');

        return is_string($path) && $path !== '' ? Storage::disk('public')->url($path) : null;
    }

    /**
     * The till's colours (resources/css/app.css tokens): light mode buttons
     * on shade 600 with shade 50 text, dark mode on shade 400 with shade 950
     * text, the focus ring to match. Null without a brand colour.
     *
     * @return array{light: array<string, string>, dark: array<string, string>, theme: string}|null
     */
    public function tillColors(?Shop $shop = null): ?array
    {
        $palette = $this->palette($shop);

        if ($palette === null) {
            return null;
        }

        $shades = $palette->shades;

        return [
            'light' => ['--primary' => $shades[600], '--primary-foreground' => $shades[50], '--ring' => $shades[600], '--sidebar-primary' => $shades[600], '--sidebar-primary-foreground' => $shades[50]],
            'dark' => ['--primary' => $shades[400], '--primary-foreground' => $shades[950], '--ring' => $shades[400], '--sidebar-primary' => $shades[400], '--sidebar-primary-foreground' => $shades[950]],
            'theme' => $shades[600],
        ];
    }

    /** The till's colours as a style block for the page's head (empty without a brand colour). */
    public function tillStyle(?Shop $shop = null): string
    {
        $colors = $this->tillColors($shop);

        if ($colors === null) {
            return '';
        }

        // Hex values only (BrandPalette), so nothing here can break out of the style block.
        $declarations = fn (array $vars): string => implode('', array_map(fn (string $name, string $value): string => "{$name}:{$value};", array_keys($vars), $vars));

        // html:root outranks app.css's :root and .dark, wherever the stylesheet loads.
        return '<style>html:root{'.$declarations($colors['light']).'}html.dark:root{'.$declarations($colors['dark']).'}</style>';
    }

    /**
     * "Suggest from logo": the logo's dominant colour, worked out here with
     * GD (no outside service). Transparent, near-white, near-black and grey
     * pixels are left out; the rest are grouped into close colours, weighted
     * by how saturated they are, and the biggest group's average wins.
     * Null when the shop has no logo, it can't be read (an SVG) or it has no
     * clear colour.
     */
    public function suggestFromLogo(?Shop $shop = null): ?string
    {
        $path = ($shop ?? $this->currentShop->getOrFail())->logo_path;

        if ($path === null || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return self::dominantColor((string) Storage::disk('public')->get($path));
    }

    public static function dominantColor(string $imageData): ?string
    {
        $source = @imagecreatefromstring($imageData);

        if ($source === false) {
            return null;
        }

        // 64 px across is plenty to find a colour, and keeps a big logo quick.
        $width = imagesx($source);
        $image = $width > 64 ? imagescale($source, 64) : $source;

        if ($image === false) {
            return null;
        }

        $buckets = [];
        $visible = 0;

        for ($x = 0, $w = imagesx($image); $x < $w; $x++) {
            for ($y = 0, $h = imagesy($image); $y < $h; $y++) {
                $index = imagecolorat($image, $x, $y);

                if ($index === false) {
                    continue;
                }

                $pixel = imagecolorsforindex($image, $index);

                if ($pixel['alpha'] > 63) {
                    continue; // mostly transparent
                }

                $visible++;

                [$red, $green, $blue] = [$pixel['red'], $pixel['green'], $pixel['blue']];
                $max = max($red, $green, $blue);
                $min = min($red, $green, $blue);
                $saturation = $max === 0 ? 0 : ($max - $min) / $max;

                if ($min > 235 || $max < 30 || $saturation < 0.2) {
                    continue; // background white, black outlines and greys
                }

                $key = ($red >> 4).'-'.($green >> 4).'-'.($blue >> 4);
                $buckets[$key] ??= ['weight' => 0.0, 'count' => 0, 'red' => 0, 'green' => 0, 'blue' => 0];
                $buckets[$key]['weight'] += $saturation;
                $buckets[$key]['count']++;
                $buckets[$key]['red'] += $red;
                $buckets[$key]['green'] += $green;
                $buckets[$key]['blue'] += $blue;
            }
        }

        if ($buckets === []) {
            return null;
        }

        uasort($buckets, fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);
        $top = reset($buckets);

        // A speck of colour on a grey logo is not its colour: the winner covers at least 2% of what is drawn.
        if ($top['count'] < 0.02 * $visible) {
            return null;
        }

        return sprintf('#%02x%02x%02x', intdiv($top['red'], $top['count']), intdiv($top['green'], $top['count']), intdiv($top['blue'], $top['count']));
    }
}
