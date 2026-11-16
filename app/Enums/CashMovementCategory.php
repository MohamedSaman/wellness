<?php

namespace App\Enums;

/** Why cash was paid out or in (docs/PHARMACY.md, branch 18). "Other" needs a note. */
enum CashMovementCategory: string
{
    case RefundDifference = 'refund_difference';
    case Expense = 'expense';
    case FloatTopUp = 'float_top_up';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::RefundDifference => 'Refund difference',
            self::Expense => 'Expense',
            self::FloatTopUp => 'Float top-up',
            self::Other => 'Other',
        };
    }

    /** @return array<string, string> value => label, for a select */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $category): array => [$category->value => $category->label()])->all();
    }
}
