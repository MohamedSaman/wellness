<?php

namespace App\Enums;

/**
 * A stock count's life (docs/PHARMACY.md §9, branch 10): started (lines put
 * on the sheet), counted, then posted as count corrections in one go, or
 * cancelled without touching stock.
 */
enum StockCountStatus: string
{
    case InProgress = 'in_progress';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In progress',
            self::Posted => 'Posted',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::InProgress => 'warning',
            self::Posted => 'success',
            self::Cancelled => 'gray',
        };
    }

    /** @return array<string, string> value => label, for a select */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])->all();
    }
}
