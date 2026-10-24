<?php

namespace Tests\Feature\Pos\Concerns;

use App\Models\LabelPrint;
use App\Models\LabelTemplate;
use App\Models\User;
use App\Services\Data\LabelRequest;
use App\Services\Labels\LabelService;
use App\Services\ModuleService;
use Illuminate\Testing\TestResponse;

/**
 * Helpers for label tests (docs/PHARMACY.md §9, branch 7b): printing, the
 * print page, and reading barcodes back from it. decodeEan13() reads the
 * printed bars with the EAN-13 tables of the standard, independently of the
 * library that drew them, so a test checks what a scanner would read.
 */
trait WorksWithLabels
{
    protected function labels(): LabelService
    {
        return app(LabelService::class);
    }

    protected function enableLabels(): void
    {
        app(ModuleService::class)->enable($this->shop, 'labels');
    }

    /**
     * @param  list<LabelRequest>  $requests
     */
    protected function printLabels(array $requests, ?User $user = null, ?LabelTemplate $template = null, string $source = 'product'): LabelPrint
    {
        return $this->labels()->print($user ?? $this->owner, $template ?? $this->labels()->defaultTemplate(), $requests, $source);
    }

    protected function printPage(LabelPrint $print, ?User $user = null, array $query = []): TestResponse
    {
        return $this->actingAs($user ?? $this->owner)->get(route('labels.print', ['labelPrint' => $print, ...$query]));
    }

    /**
     * Every barcode drawn on a print page: its encoded value and its SVG.
     *
     * @return list<array{code: string, symbology: string, svg: string}>
     */
    protected function barcodesOn(string $html): array
    {
        preg_match_all('/<svg[^>]*data-barcode="([^"]*)"[^>]*data-symbology="([^"]*)"[^>]*>.*?<\/svg>/s', $html, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match): array => ['code' => html_entity_decode($match[1]), 'symbology' => $match[2], 'svg' => $match[0]], $matches);
    }

    /** The digits an EAN-13 scanner reads from the bars of an SVG drawn by the label layout. */
    protected function decodeEan13(string $svg): string
    {
        preg_match('/viewBox="0 0 (\d+) 1"/', $svg, $viewBox);
        $this->assertSame('95', $viewBox[1] ?? null, 'An EAN-13 is 95 modules wide.');

        $modules = array_fill(0, 95, '0');
        preg_match_all('/<rect x="(\d+)" y="0" width="(\d+)"/', $svg, $rects, PREG_SET_ORDER);

        foreach ($rects as [, $x, $width]) {
            for ($i = (int) $x; $i < (int) $x + (int) $width; $i++) {
                $modules[$i] = '1';
            }
        }

        $bits = implode('', $modules);
        $this->assertSame('101', substr($bits, 0, 3), 'Start guard');
        $this->assertSame('01010', substr($bits, 45, 5), 'Centre guard');
        $this->assertSame('101', substr($bits, 92, 3), 'End guard');

        $l = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
        $g = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];
        $r = array_map(fn (string $code): string => strtr($code, '01', '10'), $l);
        $parities = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

        $left = '';
        $parity = '';

        for ($i = 0; $i < 6; $i++) {
            $chunk = substr($bits, 3 + 7 * $i, 7);
            $digit = array_search($chunk, $l, true);
            $set = 'L';

            if ($digit === false) {
                $digit = array_search($chunk, $g, true);
                $set = 'G';
            }

            $this->assertNotFalse($digit, "Left digit {$i} is not an EAN-13 pattern");
            $left .= $digit;
            $parity .= $set;
        }

        $right = '';

        for ($i = 0; $i < 6; $i++) {
            $digit = array_search(substr($bits, 50 + 7 * $i, 7), $r, true);
            $this->assertNotFalse($digit, "Right digit {$i} is not an EAN-13 pattern");
            $right .= $digit;
        }

        $first = array_search($parity, $parities, true);
        $this->assertNotFalse($first, 'The left half\'s parity gives the first digit');

        return $first.$left.$right;
    }
}
