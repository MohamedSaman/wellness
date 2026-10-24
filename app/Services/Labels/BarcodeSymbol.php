<?php

namespace App\Services\Labels;

use App\Exceptions\PosException;
use App\Support\Barcode;
use Picqer\Barcode\Exceptions\BarcodeException;
use Picqer\Barcode\Types\TypeCode128;
use Picqer\Barcode\Types\TypeEan13;
use Picqer\Barcode\Types\TypeEan8;
use Picqer\Barcode\Types\TypeUpcA;

/**
 * A barcode's bars, encoded on the server by picqer/php-barcode-generator
 * (no external service): EAN-13, EAN-8 or UPC-A for a valid one, Code 128
 * for anything else (Barcode::symbology()). Drawn as an SVG at an exact
 * size in mm, each bar a whole number of modules, so the printer draws
 * every bar as whole dots (LabelLayout picks the module width).
 */
final class BarcodeSymbol
{
    /** Quiet zone either side, in modules (GS1 for EAN/UPC; ISO 15417 for Code 128). */
    private const QUIET = [
        Barcode::EAN13 => [11, 7],
        Barcode::EAN8 => [7, 7],
        Barcode::UPCA => [9, 9],
        Barcode::CODE128 => [10, 10],
    ];

    /**
     * @param  list<array{bar: bool, width: int}>  $bars  left to right, widths in modules
     */
    private function __construct(
        public readonly string $code,
        public readonly string $symbology,
        public readonly array $bars,
        public readonly int $modules,
    ) {}

    /** @throws PosException when the code can't be printed as bars */
    public static function for(string $code): self
    {
        $symbology = Barcode::symbology($code) ?? throw new PosException("The barcode \"{$code}\" can't be printed as bars.");

        $type = match ($symbology) {
            Barcode::EAN13 => new TypeEan13,
            Barcode::EAN8 => new TypeEan8,
            Barcode::UPCA => new TypeUpcA,
            default => new TypeCode128,
        };

        try {
            $encoded = $type->getBarcode($code);
        } catch (BarcodeException $exception) {
            throw new PosException("The barcode \"{$code}\" can't be printed as bars: {$exception->getMessage()}", previous: $exception);
        }

        $bars = [];

        foreach ($encoded->getBars() as $bar) {
            $bars[] = ['bar' => $bar->isBar(), 'width' => $bar->getWidth()];
        }

        return new self($code, $symbology, $bars, $encoded->getWidth());
    }

    /** Modules of blank space needed on the left and on the right. */
    public function quietZone(): int
    {
        return array_sum(self::QUIET[$this->symbology]);
    }

    public function humanReadable(): string
    {
        return Barcode::humanReadable($this->code, $this->symbology);
    }

    /** The bars as an SVG of exactly $moduleMm per module and $heightMm high. */
    public function svg(float $moduleMm, float $heightMm): string
    {
        $rects = [];
        $x = 0;

        foreach ($this->bars as $bar) {
            if ($bar['bar']) {
                $rects[] = '<rect x="'.$x.'" y="0" width="'.$bar['width'].'" height="1"/>';
            }

            $x += $bar['width'];
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%smm" height="%smm" viewBox="0 0 %d 1" preserveAspectRatio="none" shape-rendering="crispEdges" role="img" aria-label="%s" data-barcode="%s" data-symbology="%s" data-module-mm="%s"><g fill="#000">%s</g></svg>',
            self::mm($moduleMm * $this->modules),
            self::mm($heightMm),
            $this->modules,
            e($this->code),
            e($this->code),
            $this->symbology,
            self::mm($moduleMm),
            implode('', $rects),
        );
    }

    private static function mm(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
