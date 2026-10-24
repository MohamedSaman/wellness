<?php

namespace App\Enums;

/**
 * What kind of bill a sale is (docs/PHARMACY.md §9, branch 8). A standard
 * bill prices every line from the price list; an OPD bill (an OPD till at
 * the doctor's counter) is the consultation plus one typed Medicines amount
 * spread over the medicines dispensed.
 */
enum BillType: string
{
    case Standard = 'standard';
    case Opd = 'opd';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Bill',
            self::Opd => 'OPD bill',
        };
    }
}
