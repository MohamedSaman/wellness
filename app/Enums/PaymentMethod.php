<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Other = 'other';
    // Return credit paying a new bill in an exchange at the till (docs/PHARMACY.md, branch 19).
    // Only ExchangeService records it: checkout and offline sync refuse it everywhere else.
    case Exchange = 'exchange';

    public function label(): string
    {
        return match ($this) {
            self::Exchange => 'Exchange credit',
            default => ucwords(str_replace('_', ' ', $this->value)),
        };
    }

    /** What a customer pays with at the till (and an admin refund goes back by): never Exchange credit. */
    public function isTendered(): bool
    {
        return $this !== self::Exchange;
    }

    /** @return list<self> */
    public static function tendered(): array
    {
        return array_values(array_filter(self::cases(), fn (self $method): bool => $method->isTendered()));
    }
}
