<?php

namespace App\Enums;

/** Why a customer brought items back. Stored as a code; any detail goes in the note. */
enum ReturnReason: string
{
    case WrongItem = 'wrong_item';
    case FaultyDamaged = 'faulty_damaged';
    case ChangedMind = 'changed_mind';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WrongItem => 'Wrong item',
            self::FaultyDamaged => 'Faulty / damaged',
            self::ChangedMind => 'Customer changed mind',
            self::Other => 'Other',
        };
    }

    /** Faulty or damaged goods should not go back on the shelf unless someone decides otherwise. */
    public function restocksByDefault(): bool
    {
        return $this !== self::FaultyDamaged;
    }

    /** @return array<string, string> value => label, for a select */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $reason): array => [$reason->value => $reason->label()])->all();
    }
}
