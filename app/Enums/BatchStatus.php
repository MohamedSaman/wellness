<?php

namespace App\Enums;

/**
 * Whether a batch can be sold (docs/PHARMACY.md §4.5). Only active batches
 * are offered by FEFO. Quarantine and recall are set by the stock-control
 * branch; the column and the FEFO rule exist from the batches branch on.
 */
enum BatchStatus: string
{
    case Active = 'active';
    case Quarantined = 'quarantined';
    case Recalled = 'recalled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Quarantined => 'Quarantined',
            self::Recalled => 'Recalled',
        };
    }
}
