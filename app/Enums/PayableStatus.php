<?php

namespace App\Enums;

/** How far an invoice owed to a supplier (a GRN or an opening entry) has been paid. */
enum PayableStatus: string
{
    case Unpaid = 'unpaid';
    case PartPaid = 'part_paid';
    case Paid = 'paid';
    case Reversed = 'reversed';   // its GRN or opening entry was reversed: nothing is owed

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::PartPaid => 'Part paid',
            self::Paid => 'Paid',
            self::Reversed => 'Reversed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'danger',
            self::PartPaid => 'warning',
            self::Paid => 'success',
            self::Reversed => 'gray',
        };
    }

    /** Still owed, wholly or in part. */
    public function isOpen(): bool
    {
        return $this === self::Unpaid || $this === self::PartPaid;
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Unpaid->value, self::PartPaid->value];
    }

    /** The status of an item of $amount with $allocated paid against it. */
    public static function of(int $amount, int $allocated): self
    {
        return match (true) {
            $allocated <= 0 => self::Unpaid,
            $allocated >= $amount => self::Paid,
            default => self::PartPaid,
        };
    }

    /** @return array<string, string> value => label, for a select */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])->all();
    }
}
