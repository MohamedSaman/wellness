<?php

namespace App\Enums;

/** Why stock goes back to a supplier. Stored as a code; any detail goes in the notes. */
enum SupplierReturnReason: string
{
    case Expired = 'expired';
    case NearExpiry = 'near_expiry';
    case Damaged = 'damaged';
    case Recalled = 'recalled';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Expired => 'Expired',
            self::NearExpiry => 'Near expiry',
            self::Damaged => 'Damaged',
            self::Recalled => 'Recalled',
            self::Other => 'Other',
        };
    }

    /** @return array<string, string> value => label, for a select */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $reason): array => [$reason->value => $reason->label()])->all();
    }
}
