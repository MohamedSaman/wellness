<?php

namespace App\Enums;

/**
 * What a label template prints on (docs/PHARMACY.md §9, branch 7b): a
 * thermal label roll, one label per printed page of exactly the label's
 * size, or a sticker sheet (A4), columns x rows of labels on each page.
 */
enum LabelKind: string
{
    case Roll = 'roll';
    case Sheet = 'sheet';

    public function label(): string
    {
        return match ($this) {
            self::Roll => 'Label roll (thermal label printer)',
            self::Sheet => 'Sticker sheet (A4, office printer)',
        };
    }

    /** @return array<string, string> value => label, for a select */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $kind): array => [$kind->value => $kind->label()])->all();
    }
}
