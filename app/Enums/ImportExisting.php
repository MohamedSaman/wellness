<?php

namespace App\Enums;

/**
 * What an import does with a row that matches something already on file
 * (an item by SKU or barcode, a supplier by name): leave it as it is, or
 * update it from the file. Either way nothing is ever created twice.
 */
enum ImportExisting: string
{
    case Skip = 'skip';
    case Update = 'update';

    public function label(): string
    {
        return match ($this) {
            self::Skip => 'Skip them (leave what is on file)',
            self::Update => 'Update them from the file',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $mode): array => [$mode->value => $mode->label()])->all();
    }
}
