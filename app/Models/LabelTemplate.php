<?php

namespace App\Models;

use App\Enums\LabelField;
use App\Enums\LabelKind;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $name
 * @property LabelKind $kind
 * @property string|null $preset a config('pos.labels.presets') key, null when custom
 * @property string $label_width mm
 * @property string $label_height mm
 * @property int $columns sheets: labels across a page
 * @property int $rows sheets: labels down a page
 * @property string|null $page_width mm
 * @property string|null $page_height mm
 * @property string $margin_top mm
 * @property string $margin_left mm
 * @property string $gap_x mm
 * @property string $gap_y mm
 * @property string $padding mm
 * @property int $dpi
 * @property array<string, bool> $fields LabelField value => printed
 * @property string|null $shop_name
 * @property bool $is_default
 *
 * What a label looks like (docs/PHARMACY.md §9, branch 7b): its size and,
 * for a sticker sheet, how labels sit on the page, the printer's resolution
 * and which fields it prints. Laid out by Labels\LabelLayout; set up by the
 * owner (labels.manage) in Label templates.
 */
class LabelTemplate extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => LabelKind::class,
            'label_width' => 'decimal:2',
            'label_height' => 'decimal:2',
            'columns' => 'integer',
            'rows' => 'integer',
            'page_width' => 'decimal:2',
            'page_height' => 'decimal:2',
            'margin_top' => 'decimal:2',
            'margin_left' => 'decimal:2',
            'gap_x' => 'decimal:2',
            'gap_y' => 'decimal:2',
            'padding' => 'decimal:2',
            'dpi' => 'integer',
            'fields' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function prints(LabelField $field): bool
    {
        return (bool) ($this->fields[$field->value] ?? false);
    }

    public function isSheet(): bool
    {
        return $this->kind === LabelKind::Sheet;
    }

    /** Labels on one printed page: one on a roll, columns x rows on a sheet. */
    public function perPage(): int
    {
        return $this->isSheet() ? max(1, $this->columns) * max(1, $this->rows) : 1;
    }

    /** "38 × 25 mm" */
    public function sizeLabel(): string
    {
        $mm = fn (string $value): string => rtrim(rtrim($value, '0'), '.');

        return $mm($this->label_width).' × '.$mm($this->label_height).' mm'
            .($this->isSheet() ? ", {$this->columns} × {$this->rows} a sheet" : '');
    }
}
