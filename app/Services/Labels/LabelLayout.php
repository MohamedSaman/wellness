<?php

namespace App\Services\Labels;

use App\Enums\LabelField;
use App\Exceptions\PosException;
use App\Models\LabelTemplate;

/**
 * Fits one label's text and barcode on a template's label (docs/PHARMACY.md
 * §9, branch 7b), top to bottom: shop name, item name (up to two lines),
 * strength and pack, SKU and part number, the bars with their number, then
 * batch and expiry on the left and the price on the right.
 *
 * Everything is in mm, from the label's size less its padding. The bars come
 * first: each module (narrowest bar) is a whole number of printer dots at
 * the template's dpi, as wide as the label allows up to max_module, and
 * never narrower than min_module (else the barcode is refused as too long
 * for the label: a scanner could not read it). Then the text: when it does
 * not leave min_bar_height for the bars, the largest text shrinks first,
 * down to min_font_pt, then the name goes from two lines to one; whatever
 * is still too wide is cut short with an ellipsis. If even that does not
 * fit, the label is reported as not fitting (a template can't be saved
 * like that). Batch and expiry share the price's row, or get their own when
 * they don't fit beside it. Text widths are estimated from Arial's character
 * widths; the print view also clips each line, so nothing ever spills off a
 * label.
 */
final class LabelLayout
{
    /** One typographic point in mm. */
    public const PT = 25.4 / 72;

    private const LINE_HEIGHT = 1.15;

    /** Bold text is this much wider than regular (Arial). */
    private const BOLD = 1.08;

    private const EPSILON = 0.0001;

    public function __construct(private readonly LabelTemplate $template) {}

    public function innerWidth(): float
    {
        return (float) $this->template->label_width - 2 * (float) $this->template->padding;
    }

    public function innerHeight(): float
    {
        return (float) $this->template->label_height - 2 * (float) $this->template->padding;
    }

    /** The width of one printer dot in mm. */
    public function dot(): float
    {
        return 25.4 / max(1, $this->template->dpi);
    }

    /**
     * @return array{
     *     blocks: list<array<string, mixed>>,
     *     fits: bool,
     *     problems: list<string>,
     *     used_height: float,
     *     smallest_pt: float,
     *     module: float|null,
     *     bar_width: float|null,
     *     bar_height: float|null,
     * }
     */
    public function lay(LabelData $data): array
    {
        $problems = [];
        $minFont = (float) config('pos.labels.min_font_pt', 6);
        $minBar = (float) config('pos.labels.min_bar_height', 5);
        $maxBar = (float) config('pos.labels.max_bar_height', 15);

        [$symbol, $module, $barcodeProblem] = $this->symbol($data);

        if ($barcodeProblem !== null) {
            $problems[] = $barcodeProblem;
        }

        $sizes = $this->preferredSizes();
        $nameLines = 2;
        $fits = true;

        while (true) {
            $blocks = $this->textBlocks($data, $sizes, $nameLines, $symbol);
            $textHeight = array_sum(array_column($blocks, 'height'));
            $free = $this->innerHeight() - $textHeight;
            $needed = $symbol !== null && $module !== null ? $minBar : 0.0;

            if ($free + self::EPSILON >= $needed) {
                break;
            }

            // Shrink the largest text still above the minimum, half a point at a time.
            $largest = null;

            foreach ($sizes as $key => $size) {
                if ($size - 0.5 + self::EPSILON >= $minFont && ($largest === null || $size > $sizes[$largest])) {
                    $largest = $key;
                }
            }

            if ($largest !== null) {
                $sizes[$largest] -= 0.5;

                continue;
            }

            if ($nameLines === 2 && $this->nameLineCount($blocks) === 2) {
                $nameLines = 1;

                continue;
            }

            $fits = false;
            $problems[] = sprintf('Too much to print on a %s mm label: switch off a field or choose a larger label.', $this->sizeLabel());

            break;
        }

        $barHeight = null;

        if ($symbol !== null && $module !== null) {
            $barHeight = max($minBar, min($maxBar, $free));
        }

        $blocks = $this->placeBarcode($blocks, $symbol, $module, $barHeight);
        $used = array_sum(array_column($blocks, 'height'));
        $pts = [];

        foreach ($blocks as $block) {
            $printed = match ($block['type']) {
                'text' => [$block['pt']],
                'barcode' => [$block['text'] === null ? null : $block['text_pt']],
                default => [$block['left'] === null ? null : $block['left_pt'], $block['right'] === null ? null : $block['right_pt']],
            };

            array_push($pts, ...array_map(floatval(...), array_filter($printed, fn ($pt): bool => $pt !== null)));
        }

        return [
            'blocks' => $blocks,
            'fits' => $fits && $barcodeProblem === null,
            'problems' => $problems,
            'used_height' => round($used, 3),
            'smallest_pt' => $pts === [] ? 0.0 : min($pts),
            'module' => $module,
            'bar_width' => $symbol !== null && $module !== null ? round($module * $symbol->modules, 3) : null,
            'bar_height' => $barHeight === null ? null : round($barHeight, 3),
        ];
    }

    /**
     * The symbol, its module width in mm (null when it does not fit), and
     * the problem with it, if any.
     *
     * @return array{0: BarcodeSymbol|null, 1: float|null, 2: string|null}
     */
    private function symbol(LabelData $data): array
    {
        if (! $this->template->prints(LabelField::Barcode)) {
            return [null, null, null];
        }

        if (blank($data->barcode)) {
            return [null, null, "{$data->name} has no barcode."];
        }

        try {
            $symbol = BarcodeSymbol::for((string) $data->barcode);
        } catch (PosException $exception) {
            return [null, null, $exception->getMessage()];
        }

        $module = $this->moduleWidth($symbol);

        if ($module === null) {
            $needs = ($symbol->modules + $symbol->quietZone()) * (float) config('pos.labels.min_module', 0.25);

            return [$symbol, null, sprintf(
                'The barcode %s is too long for a %s mm wide label: it needs %s mm to scan. Choose a wider label.',
                $symbol->code,
                self::number((float) $this->template->label_width),
                self::number(ceil($needs * 10) / 10),
            )];
        }

        return [$symbol, $module, null];
    }

    /**
     * The widest module, in whole printer dots, that fits the bars inside
     * the label's padding and the bars with their quiet zones on the label;
     * null when that is narrower than min_module.
     */
    public function moduleWidth(BarcodeSymbol $symbol): ?float
    {
        $max = min(
            (float) config('pos.labels.max_module', 0.40),
            $this->innerWidth() / $symbol->modules,
            (float) $this->template->label_width / ($symbol->modules + $symbol->quietZone()),
        );

        $dots = (int) floor($max / $this->dot() + self::EPSILON);
        $module = $dots * $this->dot();

        return $dots < 1 || $module + self::EPSILON < (float) config('pos.labels.min_module', 0.25) ? null : $module;
    }

    /**
     * Font sizes in points the layout starts from, larger on taller labels.
     *
     * @return array<string, float>
     */
    private function preferredSizes(): array
    {
        $scale = max(1.0, min(1.5, $this->innerHeight() / 22));
        $round = fn (float $pt): float => round($pt * $scale * 2) / 2;

        return [
            'shop' => $round(6.5),
            'name' => $round(8),
            'detail' => $round(7),
            'codes' => $round(6.5),
            'hri' => $round(6.5),
            'left' => $round(6.5),
            'price' => $round(9),
        ];
    }

    /**
     * The text rows, with the barcode's place held by a marker (placed once
     * the bars' height is known).
     *
     * @param  array<string, float>  $sizes
     * @return list<array<string, mixed>>
     */
    private function textBlocks(LabelData $data, array $sizes, int $nameLines, ?BarcodeSymbol $symbol): array
    {
        $t = $this->template;
        $width = $this->innerWidth();
        $blocks = [];

        if ($t->prints(LabelField::ShopName) && filled($data->shopName)) {
            $blocks[] = $this->text('shop', [$this->fitLine((string) $data->shopName, $sizes['shop'], true, $width)], $sizes['shop'], true);
        }

        if ($t->prints(LabelField::Name)) {
            $blocks[] = $this->text('name', $this->wrap($data->name, $sizes['name'], true, $width, $nameLines), $sizes['name'], true);
        }

        $detail = implode(' · ', array_filter([
            $t->prints(LabelField::Strength) ? $data->strength : null,
            $t->prints(LabelField::Pack) ? $data->pack : null,
        ], fn (?string $part): bool => filled($part)));

        if ($detail !== '') {
            $blocks[] = $this->text('detail', [$this->fitLine($detail, $sizes['detail'], false, $width)], $sizes['detail'], false);
        }

        $codes = implode(' · ', array_filter([
            $t->prints(LabelField::Sku) && filled($data->sku) ? "SKU {$data->sku}" : null,
            $t->prints(LabelField::PartNumber) && filled($data->partNumber) ? "Part no. {$data->partNumber}" : null,
        ]));

        if ($codes !== '') {
            $blocks[] = $this->text('codes', [$this->fitLine($codes, $sizes['codes'], false, $width)], $sizes['codes'], false);
        }

        $digits = $t->prints(LabelField::BarcodeText) && filled($data->barcode)
            ? ($symbol?->humanReadable() ?? (string) $data->barcode)
            : null;

        if ($t->prints(LabelField::Barcode) || $digits !== null) {
            $blocks[] = [
                'type' => 'barcode',
                'key' => 'barcode',
                'text' => $digits === null ? null : $this->fitLine($digits, $sizes['hri'], false, $width),
                'text_pt' => $sizes['hri'],
                'height' => $digits === null ? 0.0 : $this->lineHeight($sizes['hri']),
            ];
        }

        $price = $t->prints(LabelField::Price) && filled($data->price) ? (string) $data->price : null;
        $left = implode(' · ', array_filter([
            $t->prints(LabelField::Batch) && filled($data->batchNo) ? "B {$data->batchNo}" : null,
            $t->prints(LabelField::Expiry) && filled($data->expiry) ? "Exp {$data->expiry}" : null,
        ]));

        if ($price !== null || $left !== '') {
            $priceWidth = $price === null ? 0.0 : $this->textWidth($price, $sizes['price'], true);
            $leftWidth = max(0.0, $width - $priceWidth - ($price === null ? 0.0 : 1.0));

            // Batch and expiry go on a row of their own when they don't fit beside the price.
            if ($left !== '' && $price !== null && $this->textWidth($left, $sizes['left'], false) > $leftWidth + self::EPSILON) {
                $blocks[] = $this->text('batch', [$this->fitLine($left, $sizes['left'], false, $width)], $sizes['left'], false);
                $left = '';
            }

            $blocks[] = [
                'type' => 'split',
                'key' => 'footer',
                'left' => $left === '' ? null : $this->fitLine($left, $sizes['left'], false, $leftWidth),
                'left_pt' => $sizes['left'],
                'right' => $price === null ? null : $this->fitLine($price, $sizes['price'], true, $width),
                'right_pt' => $sizes['price'],
                'height' => max(
                    $left === '' ? 0.0 : $this->lineHeight($sizes['left']),
                    $price === null ? 0.0 : $this->lineHeight($sizes['price']),
                ),
            ];
        }

        return $blocks;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function placeBarcode(array $blocks, ?BarcodeSymbol $symbol, ?float $module, ?float $barHeight): array
    {
        foreach ($blocks as $i => $block) {
            if ($block['type'] !== 'barcode') {
                continue;
            }

            $bars = $symbol !== null && $module !== null && $barHeight !== null;

            $blocks[$i] = [
                ...$block,
                'svg' => $bars ? $symbol->svg($module, $barHeight) : null,
                'code' => $symbol?->code,
                'symbology' => $symbol?->symbology,
                'module' => $module,
                'bar_height' => $bars ? $barHeight : 0.0,
                'height' => (float) $block['height'] + ($bars ? $barHeight : 0.0),
            ];
        }

        return $blocks;
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, mixed>
     */
    private function text(string $key, array $lines, float $pt, bool $bold): array
    {
        return [
            'type' => 'text',
            'key' => $key,
            'lines' => $lines,
            'pt' => $pt,
            'bold' => $bold,
            'height' => count($lines) * $this->lineHeight($pt),
        ];
    }

    /** @param  list<array<string, mixed>>  $blocks */
    private function nameLineCount(array $blocks): int
    {
        foreach ($blocks as $block) {
            if ($block['key'] === 'name') {
                return count($block['lines']);
            }
        }

        return 0;
    }

    private function lineHeight(float $pt): float
    {
        return $pt * self::PT * self::LINE_HEIGHT;
    }

    /**
     * Estimated printed width in mm, from Arial's character widths (in ems,
     * rounded up a little; a character not listed counts as wide).
     */
    public function textWidth(string $text, float $pt, bool $bold): float
    {
        $ems = 0.0;

        foreach (mb_str_split($text) as $char) {
            $ems += self::charWidth($char);
        }

        return $ems * $pt * self::PT * ($bold ? self::BOLD : 1.0);
    }

    private static function charWidth(string $char): float
    {
        return match (true) {
            str_contains(" .,:;'|!ijlI", $char) => 0.28,
            str_contains('ftr()-/[]·', $char) => 0.34,
            ctype_digit($char), str_contains('abcdeghknopqsuvxyz$#_+=×', $char) => 0.56,
            str_contains('mwMW%…', $char) => 0.94,
            ctype_upper($char) => 0.73,
            default => 0.6,
        };
    }

    /** The text, cut short with an ellipsis when it is wider than $width. */
    private function fitLine(string $text, float $pt, bool $bold, float $width): string
    {
        $text = trim($text);

        if ($this->textWidth($text, $pt, $bold) <= $width + self::EPSILON) {
            return $text;
        }

        $chars = mb_strlen($text);

        while ($chars > 1 && $this->textWidth(rtrim(mb_substr($text, 0, $chars)).'…', $pt, $bold) > $width + self::EPSILON) {
            $chars--;
        }

        return rtrim(mb_substr($text, 0, $chars)).'…';
    }

    /**
     * Word-wrapped into at most $maxLines lines; the last line is cut short
     * with an ellipsis when the text does not fit.
     *
     * @return list<string>
     */
    private function wrap(string $text, float $pt, bool $bold, float $width, int $maxLines): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $i => $word) {
            $candidate = $current === '' ? $word : "{$current} {$word}";

            if ($current === '' || $this->textWidth($candidate, $pt, $bold) <= $width + self::EPSILON) {
                $current = $candidate;

                continue;
            }

            if (count($lines) + 1 === $maxLines) {
                $current = $current.' '.implode(' ', array_slice($words, $i));

                break;
            }

            $lines[] = $current;
            $current = $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return array_map(fn (string $line): string => $this->fitLine($line, $pt, $bold, $width), $lines);
    }

    private function sizeLabel(): string
    {
        return self::number((float) $this->template->label_width).' × '.self::number((float) $this->template->label_height);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
