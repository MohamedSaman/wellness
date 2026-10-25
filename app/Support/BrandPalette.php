<?php

namespace App\Support;

use Filament\Support\Colors\Color;

/**
 * A full shade scale (50 to 950) from one brand colour, readable in light
 * and dark mode (WCAG AA, 4.5:1 for text).
 *
 * The scale keeps the brand's hue and chroma and puts the colour itself at
 * the shade whose lightness is nearest (a teal of lightness 0.51 is the
 * 700); the other shades sit at Tailwind's lightness steps, their chroma
 * scaled the same way and pulled back into sRGB. Then the pairs the admin
 * panel and the till actually draw are checked, and a shade that fails is
 * moved (never the status colours, which are not part of the brand):
 *   - a button: light text (shade 50) on shade 600, in both modes;
 *   - dark mode text and the till's dark button: shade 400 on the panel's
 *     dark surface, and shade 950 text on shade 400.
 * Contrast is the WCAG ratio, and Filament's own estimate must agree, so the
 * panel picks the light text the till uses.
 */
final class BrandPalette
{
    public const SHADES = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

    /** The minimum contrast for text (WCAG AA, normal size). */
    public const AA_TEXT = 4.5;

    /** The admin panel's dark section surface (Filament's zinc 900). */
    public const DARK_SURFACE = '#18181b';

    /** Lightness and relative chroma per shade (Filament's generated palettes). */
    private const STEPS = [
        50 => [0.977, 0.0140],
        100 => [0.950, 0.0327],
        200 => [0.905, 0.0632],
        300 => [0.840, 0.1060],
        400 => [0.754, 0.1503],
        500 => [0.683, 0.1701],
        600 => [0.598, 0.1691],
        700 => [0.515, 0.1494],
        800 => [0.446, 0.1233],
        900 => [0.395, 0.0996],
        950 => [0.278, 0.0714],
    ];

    /**
     * @param  array<int, string>  $shades  shade => #rrggbb
     * @param  list<int>  $adjusted  shades moved for contrast
     */
    private function __construct(
        public string $brand,
        public int $anchor,
        public array $shades,
        public array $adjusted,
    ) {}

    public static function isValid(?string $hex): bool
    {
        return is_string($hex) && preg_match('/^#[0-9a-f]{6}$/i', $hex) === 1;
    }

    /**
     * @param  int|null  $anchor  the shade the colour itself becomes; by default the one nearest its lightness
     *                            (a theme preset puts its primary on 600, the panel's button shade)
     */
    public static function from(string $hex, ?int $anchor = null): self
    {
        if (! self::isValid($hex)) {
            throw new \InvalidArgumentException("Not a colour: {$hex}. Use #rrggbb.");
        }

        $brand = strtolower($hex);
        [$lightness, $chroma, $hue] = self::toOklch($brand);
        $achromatic = $chroma < 0.02;

        // The brand sits at the shade nearest its own lightness, unless the caller says which.
        $placed = $anchor !== null && array_key_exists($anchor, self::STEPS);

        if (! $placed) {
            $anchor = 50;

            foreach (self::STEPS as $shade => [$step]) {
                if (abs($step - $lightness) < abs(self::STEPS[$anchor][0] - $lightness)) {
                    $anchor = $shade;
                }
            }
        }

        $lightnesses = [];
        $chromas = [];
        $shades = [];

        $anchorStep = self::STEPS[$anchor][0];

        foreach (self::STEPS as $shade => [$step, $relative]) {
            // A placed colour far from its own step stretches the scale around it, so the
            // shades either side stay evenly spaced (lighter towards white, darker towards black).
            if ($placed && $shade !== $anchor) {
                $step = $step > $anchorStep
                    ? 1 - (1 - $step) * (1 - $lightness) / (1 - $anchorStep)
                    : $step * $lightness / $anchorStep;
            }

            $lightnesses[$shade] = $shade === $anchor ? $lightness : $step;
            $chromas[$shade] = $achromatic ? $chroma : min(0.37, $chroma * $relative / self::STEPS[$anchor][1]);
            $shades[$shade] = $shade === $anchor ? $brand : self::toHex($lightnesses[$shade], $chromas[$shade], $hue);
        }

        $palette = new self($brand, $anchor, $shades, []);
        $palette->enforceContrast($lightnesses, $chromas, $hue);

        return $palette;
    }

    /** @return array<int, string> the scale as Filament takes it: shade => oklch() */
    public function forFilament(): array
    {
        return array_map(fn (string $hex): string => self::oklchString($hex), $this->shades);
    }

    /**
     * WCAG contrast ratio of two #rrggbb colours, and no more than Filament's
     * own estimate (which decides the text colour on its buttons).
     */
    public static function contrast(string $first, string $second): float
    {
        $wcag = fn (string $hex): float => array_sum(array_map(
            fn (float $channel, float $weight): float => $weight * ($channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4),
            self::rgb($hex),
            [0.2126, 0.7152, 0.0722],
        ));
        [$light, $dark] = [max($wcag($first), $wcag($second)), min($wcag($first), $wcag($second))];

        return min(($light + 0.05) / ($dark + 0.05), Color::calculateContrastRatio($first, $second));
    }

    /**
     * @param  array<int, float>  $lightnesses
     * @param  array<int, float>  $chromas
     */
    private function enforceContrast(array $lightnesses, array $chromas, float $hue): void
    {
        $set = function (int $shade, float $lightness) use (&$lightnesses, $chromas, $hue): void {
            $lightnesses[$shade] = $lightness;
            $this->shades[$shade] = self::toHex($lightness, $chromas[$shade], $hue);

            if (! in_array($shade, $this->adjusted, true)) {
                $this->adjusted[] = $shade;
            }
        };

        // Buttons: light text (shade 50) on shade 600; darker shades stay darker.
        while (self::contrast($this->shades[50], $this->shades[600]) < self::AA_TEXT && $lightnesses[600] > 0.2) {
            $set(600, $lightnesses[600] - 0.01);
        }

        foreach ([700 => 600, 800 => 700, 900 => 800, 950 => 900] as $shade => $lighter) {
            if ($lightnesses[$shade] > $lightnesses[$lighter] - 0.01) {
                $set($shade, max(0.12, $lightnesses[$lighter] - 0.01));
            }
        }

        // Dark mode: shade 400 reads on the dark surface; lighter shades stay lighter.
        while (self::contrast($this->shades[400], self::DARK_SURFACE) < self::AA_TEXT && $lightnesses[400] < 0.95) {
            $set(400, $lightnesses[400] + 0.01);
        }

        foreach ([300 => 400, 200 => 300, 100 => 200, 50 => 100] as $shade => $darker) {
            if ($lightnesses[$shade] < $lightnesses[$darker] + 0.01) {
                $set($shade, min(0.99, $lightnesses[$darker] + 0.01));
            }
        }

        // The till's dark button: shade 950 text on shade 400.
        while (self::contrast($this->shades[950], $this->shades[400]) < self::AA_TEXT && $lightnesses[950] > 0.12) {
            $set(950, $lightnesses[950] - 0.01);
        }

        sort($this->adjusted);
    }

    /** @return array{float, float, float} OKLCH lightness, chroma, hue in degrees */
    public static function toOklch(string $hex): array
    {
        [$red, $green, $blue] = array_map(
            fn (float $channel): float => $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4,
            self::rgb($hex),
        );

        $long = (0.4122214708 * $red + 0.5363325363 * $green + 0.0514459929 * $blue) ** (1 / 3);
        $medium = (0.2119034982 * $red + 0.6806995451 * $green + 0.1073969566 * $blue) ** (1 / 3);
        $short = (0.0883024619 * $red + 0.2817188376 * $green + 0.6299787005 * $blue) ** (1 / 3);

        $lightness = 0.2104542553 * $long + 0.7936177850 * $medium - 0.0040720468 * $short;
        $a = 1.9779984951 * $long - 2.4285922050 * $medium + 0.4505937099 * $short;
        $b = 0.0259040371 * $long + 0.7827717662 * $medium - 0.8086757660 * $short;
        $hue = rad2deg(atan2($b, $a));

        return [$lightness, sqrt($a * $a + $b * $b), $hue < 0 ? $hue + 360 : $hue];
    }

    /** OKLCH to #rrggbb, lowering the chroma until the colour fits in sRGB. */
    public static function toHex(float $lightness, float $chroma, float $hue): string
    {
        $lightness = max(0.0, min(1.0, $lightness));
        $rgb = self::linearRgb($lightness, $chroma, $hue);

        if (! self::inGamut($rgb)) {
            [$low, $high] = [0.0, $chroma];

            for ($i = 0; $i < 20; $i++) {
                $middle = ($low + $high) / 2;
                self::inGamut(self::linearRgb($lightness, $middle, $hue)) ? $low = $middle : $high = $middle;
            }

            $rgb = self::linearRgb($lightness, $low, $hue);
        }

        return '#'.implode('', array_map(function (float $channel): string {
            $channel = max(0.0, min(1.0, $channel));
            $encoded = $channel <= 0.0031308 ? 12.92 * $channel : 1.055 * $channel ** (1 / 2.4) - 0.055;

            return str_pad(dechex((int) round(max(0.0, min(1.0, $encoded)) * 255)), 2, '0', STR_PAD_LEFT);
        }, $rgb));
    }

    public static function oklchString(string $hex): string
    {
        [$lightness, $chroma, $hue] = self::toOklch($hex);

        return sprintf('oklch(%.4f %.4f %.3f)', $lightness, $chroma, $hue);
    }

    /** @return array{float, float, float} linear sRGB */
    private static function linearRgb(float $lightness, float $chroma, float $hue): array
    {
        $a = $chroma * cos(deg2rad($hue));
        $b = $chroma * sin(deg2rad($hue));

        $long = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $medium = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $short = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        return [
            4.0767416621 * $long - 3.3077115913 * $medium + 0.2309699292 * $short,
            -1.2684380046 * $long + 2.6097574011 * $medium - 0.3413193965 * $short,
            -0.0041960863 * $long - 0.7034186147 * $medium + 1.7076147010 * $short,
        ];
    }

    /** @param  array{float, float, float}  $rgb */
    private static function inGamut(array $rgb): bool
    {
        foreach ($rgb as $channel) {
            if ($channel < -0.0001 || $channel > 1.0001) {
                return false;
            }
        }

        return true;
    }

    /** @return array{float, float, float} sRGB channels 0..1 */
    private static function rgb(string $hex): array
    {
        $value = hexdec(ltrim($hex, '#'));

        return [(($value >> 16) & 255) / 255, (($value >> 8) & 255) / 255, ($value & 255) / 255];
    }
}
