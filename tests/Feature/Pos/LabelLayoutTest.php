<?php

namespace Tests\Feature\Pos;

use App\Enums\LabelField;
use App\Models\LabelTemplate;
use App\Services\Labels\LabelData;
use App\Services\Labels\LabelLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Pos\Concerns\WorksWithLabels;

/**
 * Laying labels out (docs/PHARMACY.md §9, branch 7b): at every preset size
 * the sample labels fit, the text stays at 6 pt or more, and the barcode's
 * narrowest bar is at least 0.25 mm in whole printer dots, inside the label
 * with its quiet zones. Too small a label, or too long a barcode, is
 * refused rather than printed unreadable.
 */
class LabelLayoutTest extends PharmacyTestCase
{
    use WorksWithLabels;

    /** @return array<string, array{string}> */
    public static function presets(): array
    {
        return array_combine(
            ['roll_38x25', 'roll_50x25', 'roll_50x30', 'roll_40x30', 'roll_58x40', 'a4_3x8', 'a4_4x10', 'a4_5x13'],
            [['roll_38x25'], ['roll_50x25'], ['roll_50x30'], ['roll_40x30'], ['roll_58x40'], ['a4_3x8'], ['a4_4x10'], ['a4_5x13']],
        );
    }

    /** @param  array<string, mixed>  $overrides */
    private function template(string $preset, array $overrides = []): LabelTemplate
    {
        return $this->labels()->makeTemplate([...$this->labels()->newTemplateAttributes($preset), 'name' => 'Test', ...$overrides]);
    }

    /** @return array<string, bool> */
    private function allFields(): array
    {
        return array_fill_keys(array_map(fn (LabelField $field): string => $field->value, LabelField::cases()), true);
    }

    private function assertReadableAndScannable(LabelTemplate $template, string $context): void
    {
        $this->assertSame([], $this->labels()->problems($template), $context);

        $layout = new LabelLayout($template);

        foreach ($this->labels()->preview($template) as $i => $label) {
            $where = "{$context}, sample {$i}";

            $this->assertTrue($label['fits'], $where);
            $this->assertLessThanOrEqual($layout->innerHeight() + 0.001, $label['used_height'], "{$where}: no overflow");
            $this->assertGreaterThanOrEqual(6.0, $label['smallest_pt'], "{$where}: readable");
            $this->assertNotNull($label['module'], $where);
            $this->assertGreaterThanOrEqual(0.25, $label['module'], "{$where}: bars wide enough to scan");
            $this->assertEqualsWithDelta(round($label['module'] / $layout->dot()), $label['module'] / $layout->dot(), 0.0001, "{$where}: whole dots");
            $this->assertLessThanOrEqual($layout->innerWidth() + 0.001, $label['bar_width'], "{$where}: bars inside the label");
            $this->assertLessThanOrEqual((float) $template->label_width + 0.001, $label['module'] * (95 + 18), "{$where}: quiet zones on the label");
            $this->assertGreaterThanOrEqual(5.0, $label['bar_height'], "{$where}: bars tall enough");

            foreach ($label['blocks'] as $block) {
                if ($block['type'] === 'text') {
                    $this->assertLessThanOrEqual(2, count($block['lines']), "{$where}: at most two lines of {$block['key']}");

                    foreach ($block['lines'] as $line) {
                        $this->assertLessThanOrEqual($layout->innerWidth() + 0.001, $layout->textWidth($line, $block['pt'], $block['bold']), "{$where}: {$line} fits across");
                    }
                }
            }
        }
    }

    #[DataProvider('presets')]
    public function test_every_preset_prints_readable_scannable_labels_with_the_default_fields(string $preset): void
    {
        $this->assertReadableAndScannable($this->template($preset), $preset);
    }

    /** @return array<string, array{string}> */
    public static function roomyPresets(): array
    {
        return array_diff_key(self::presets(), ['a4_5x13' => true]);
    }

    #[DataProvider('roomyPresets')]
    public function test_every_preset_but_the_smallest_sticker_still_fits_with_every_field_switched_on(string $preset): void
    {
        $this->assertReadableAndScannable($this->template($preset, ['fields' => $this->allFields()]), "{$preset}, every field");
    }

    public function test_the_smallest_sticker_refuses_every_field_at_once_and_says_why(): void
    {
        $problems = $this->labels()->problems($this->template('a4_5x13', ['fields' => $this->allFields()]));

        $this->assertSame(['Too much to print on a 38.1 × 21.2 mm label: switch off a field or choose a larger label.'], $problems);
        $this->assertSame([], $this->labels()->problems($this->template('a4_5x13', ['fields' => [...$this->allFields(), 'shop_name' => false]])), 'One field fewer fits');
    }

    public function test_a_long_name_shrinks_then_wraps_to_two_lines_then_is_cut_short(): void
    {
        $layout = new LabelLayout($this->template('roll_38x25'));

        $label = $layout->lay(new LabelData('Co-amoxiclav (Amoxicillin + Clavulanic acid) 625 mg Film-coated Tablets, blister pack for hospital use', '2000000000015'));
        $name = collect($label['blocks'])->firstWhere('key', 'name');

        $this->assertCount(2, $name['lines']);
        $this->assertStringEndsWith('…', $name['lines'][1]);
        $this->assertTrue($label['fits']);

        $short = collect($layout->lay(new LabelData('Panadol', '2000000000015'))['blocks'])->firstWhere('key', 'name');
        $this->assertSame(['Panadol'], $short['lines']);
    }

    public function test_the_expiry_is_off_by_default_and_prints_when_switched_on(): void
    {
        $data = new LabelData('Panadol 500 mg Tablets', '2000000000015', batchNo: 'PCM118', expiry: '11/2027');

        $default = $this->template('roll_38x25');
        $this->assertFalse($default->prints(LabelField::Expiry), 'As on receipts (2026-10-21)');
        $this->assertTrue($default->prints(LabelField::Batch));
        $this->assertSame('B PCM118', collect((new LabelLayout($default))->lay($data)['blocks'])->firstWhere('key', 'footer')['left']);

        $withExpiry = $this->template('roll_38x25', ['fields' => [...$default->fields, 'expiry' => true]]);
        $this->assertSame('B PCM118 · Exp 11/2027', collect((new LabelLayout($withExpiry))->lay($data)['blocks'])->firstWhere('key', 'footer')['left']);
    }

    public function test_a_label_too_narrow_for_the_barcode_is_refused(): void
    {
        $problems = $this->labels()->problems($this->template('roll_38x25', ['preset' => null, 'label_width' => 25, 'label_height' => 25]));

        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('too long for a 25 mm wide label', $problems[0]);
    }

    public function test_too_many_fields_for_a_low_label_is_refused(): void
    {
        $problems = $this->labels()->problems($this->template('roll_50x25', ['label_height' => 12, 'fields' => $this->allFields()]));

        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('Too much to print on a 50 × 12 mm label', implode(' ', $problems));
    }

    public function test_a_long_code_128_barcode_does_not_fit_a_narrow_label_but_fits_a_wide_one(): void
    {
        $data = new LabelData('Brake pad set', 'BP-110-FRONT-OEM');

        $narrow = (new LabelLayout($this->template('roll_38x25')))->lay($data);
        $this->assertFalse($narrow['fits']);
        $this->assertStringContainsString('BP-110-FRONT-OEM is too long', $narrow['problems'][0]);
        $this->assertNull(collect($narrow['blocks'])->firstWhere('key', 'barcode')['svg'], 'No bars a scanner could misread');

        $wide = (new LabelLayout($this->template('roll_58x40', ['preset' => null, 'label_width' => 80])))->lay($data);
        $this->assertTrue($wide['fits']);
        $this->assertGreaterThanOrEqual(0.25, $wide['module']);
    }

    public function test_bars_are_whole_dots_at_each_printer_resolution(): void
    {
        foreach ([203 => 2, 300 => 3, 600 => 7] as $dpi => $dots) {
            $layout = new LabelLayout($this->template('roll_38x25', ['dpi' => $dpi]));
            $module = $layout->lay(new LabelData('Panadol', '2000000000015'))['module'];

            $this->assertEqualsWithDelta($dots * 25.4 / $dpi, $module, 0.00001, "{$dpi} dpi");
        }
    }

    public function test_a_sheet_whose_labels_run_off_the_page_is_refused(): void
    {
        $problems = $this->labels()->problems($this->template('a4_5x13', ['margin_left' => 10]));

        $this->assertStringContainsString('don\'t fit on the sheet', implode(' ', $problems));
        $this->assertSame([], $this->labels()->problems($this->template('a4_5x13')));
    }
}
