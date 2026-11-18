<?php

namespace App\Enums;

/** Why a customer brought items back. Stored as a code; any detail goes in the note. */
enum ReturnReason: string
{
    case WrongItem = 'wrong_item';
    case FaultyDamaged = 'faulty_damaged';
    case ChangedMind = 'changed_mind';
    case Other = 'other';
    // Returns and exchanges at the till (docs/PHARMACY.md, branch 19).
    case NotEffective = 'not_effective';
    case SideEffects = 'side_effects';
    case WrongMedicine = 'wrong_medicine';

    public function label(): string
    {
        return match ($this) {
            self::WrongItem => 'Wrong item',
            self::FaultyDamaged => 'Faulty / damaged',
            self::ChangedMind => 'Customer changed mind',
            self::Other => 'Other',
            self::NotEffective => 'Not effective',
            self::SideEffects => 'Side effects',
            self::WrongMedicine => 'Wrong medicine',
        };
    }

    /** Faulty or damaged goods should not go back on the shelf unless someone decides otherwise. */
    public function restocksByDefault(): bool
    {
        return $this !== self::FaultyDamaged;
    }

    /** @return array<string, string> value => label, for the admin's Return items */
    public static function options(): array
    {
        return self::labels([self::WrongItem, self::FaultyDamaged, self::ChangedMind, self::Other]);
    }

    /** @return array<string, string> value => label: the till's Return / Exchange reasons (branch 19) */
    public static function tillOptions(): array
    {
        return self::labels(self::tillReasons());
    }

    /** @return list<self> */
    public static function tillReasons(): array
    {
        return [self::NotEffective, self::SideEffects, self::WrongMedicine, self::Other];
    }

    /** @return array<string, string> value => label of every reason (filters) */
    public static function allOptions(): array
    {
        return self::labels(self::cases());
    }

    /**
     * @param  list<self>  $reasons
     * @return array<string, string>
     */
    private static function labels(array $reasons): array
    {
        return collect($reasons)->mapWithKeys(fn (self $reason): array => [$reason->value => $reason->label()])->all();
    }
}
