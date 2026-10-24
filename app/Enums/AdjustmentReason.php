<?php

namespace App\Enums;

/** Why stock was adjusted by hand. Stored as a code; the free-text detail goes in the note. */
enum AdjustmentReason: string
{
    case Damaged = 'damaged';
    case LostStolen = 'lost_stolen';
    case CountCorrection = 'count_correction';
    case Expired = 'expired';
    case SupplierReturn = 'supplier_return';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Damaged => 'Damaged',
            self::LostStolen => 'Lost / stolen',
            self::CountCorrection => 'Count correction',
            self::Expired => 'Expired',
            self::SupplierReturn => 'Supplier return',
            self::Other => 'Other',
        };
    }

    /** "Other" says nothing by itself, so it needs a note. */
    public function requiresNote(): bool
    {
        return $this === self::Other;
    }

    /** The reasons a batch is written off for (expiry write-off, docs/PHARMACY.md §9, branch 10). */
    public function isWriteOff(): bool
    {
        return in_array($this, [self::Expired, self::Damaged, self::Other], true);
    }

    /** @return array<string, string> value => label, for the write-off form */
    public static function writeOffOptions(): array
    {
        return collect(self::cases())->filter(fn (self $reason): bool => $reason->isWriteOff())
            ->mapWithKeys(fn (self $reason): array => [$reason->value => $reason->label()])
            ->all();
    }

    /** @return array<string, string> value => label, for a select */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $reason): array => [$reason->value => $reason->label()])->all();
    }
}
