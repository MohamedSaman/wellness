<?php

namespace App\Enums;

/**
 * Who may sell a medicine (docs/PHARMACY.md §4.1). Stored on drug_details;
 * the checkout branch enforces it (prescription reference, controlled-drug
 * register). Until then it is recorded and filtered on only.
 */
enum DrugSchedule: string
{
    case Otc = 'otc';
    case PrescriptionOnly = 'prescription_only';
    case Controlled = 'controlled';

    public function label(): string
    {
        return match ($this) {
            self::Otc => 'Over the counter',
            self::PrescriptionOnly => 'Prescription only',
            self::Controlled => 'Controlled',
        };
    }

    /** Short form for table badges. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Otc => 'OTC',
            self::PrescriptionOnly => 'Rx',
            self::Controlled => 'Controlled',
        };
    }

    /** @return array<string, string> value => label, for a select */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $schedule): array => [$schedule->value => $schedule->label()])->all();
    }
}
