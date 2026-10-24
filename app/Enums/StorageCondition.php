<?php

namespace App\Enums;

/**
 * How a medicine must be kept (docs/PHARMACY.md §4.1). Optional, next to the
 * free-text storage note. The returns work (§6.5) reads it: fridge items are
 * not put back on the shelf by default, because the cold chain can't be
 * checked once they have left the pharmacy.
 */
enum StorageCondition: string
{
    case Room = 'room';
    case Cool = 'cool';
    case Fridge = 'fridge';

    public function label(): string
    {
        return match ($this) {
            self::Room => 'Room temperature',
            self::Cool => 'Cool place',
            self::Fridge => 'Fridge (2-8 °C)',
        };
    }

    /** @return array<string, string> value => label, for a select */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $condition): array => [$condition->value => $condition->label()])->all();
    }
}
