<?php

namespace App\Enums;

/**
 * A stock request's life (docs/PHARMACY.md §9, branch 10): a location asks
 * another for stock; each fulfilment posts a transfer.
 *
 *   requested -> partly sent -> sent
 *        \-----------\-------> cancelled (what was sent stays sent)
 */
enum StockRequestStatus: string
{
    case Requested = 'requested';
    case PartlySent = 'partly_sent';
    case Sent = 'sent';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::PartlySent => 'Partly sent',
            self::Sent => 'Sent',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Requested => 'warning',
            self::PartlySent => 'info',
            self::Sent => 'success',
            self::Cancelled => 'gray',
        };
    }

    /** Still waiting for stock: requested, or partly sent. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::PartlySent], true);
    }

    /** @return list<string> the open statuses' values, for queries */
    public static function openValues(): array
    {
        return [self::Requested->value, self::PartlySent->value];
    }

    /** @return array<string, string> value => label, for a select */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])->all();
    }
}
