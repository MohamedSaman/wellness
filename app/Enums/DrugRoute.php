<?php

namespace App\Enums;

/** How a medicine is taken or given (docs/PHARMACY.md §4.1). Optional, for display and reports. */
enum DrugRoute: string
{
    case Oral = 'oral';
    case Topical = 'topical';
    case Inhalation = 'inhalation';
    case Injection = 'injection';
    case Eye = 'eye';
    case Ear = 'ear';
    case Nasal = 'nasal';
    case Rectal = 'rectal';
    case Vaginal = 'vaginal';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Oral => 'Oral',
            self::Topical => 'Topical (skin)',
            self::Inhalation => 'Inhalation',
            self::Injection => 'Injection',
            self::Eye => 'Eye',
            self::Ear => 'Ear',
            self::Nasal => 'Nasal',
            self::Rectal => 'Rectal',
            self::Vaginal => 'Vaginal',
            self::Other => 'Other',
        };
    }

    /** @return array<string, string> value => label, for a select */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $route): array => [$route->value => $route->label()])->all();
    }
}
