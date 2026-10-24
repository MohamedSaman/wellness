<?php

namespace App\Enums;

/**
 * The form a medicine comes in. Each form suggests a base unit, whole-unit
 * selling, loose sale and pack levels (config('pos.pharmacy.unit_defaults'),
 * docs/PHARMACY.md "Unit defaults"); the medicine form fills them in and
 * every value stays editable per product.
 */
enum DosageForm: string
{
    case Tablet = 'tablet';
    case Capsule = 'capsule';
    case Syrup = 'syrup';
    case Suspension = 'suspension';
    case Drops = 'drops';
    case Cream = 'cream';
    case Ointment = 'ointment';
    case Gel = 'gel';
    case Inhaler = 'inhaler';
    case Injection = 'injection';
    case Other = 'other';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return array<string, string> value => label, for a select */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $form): array => [$form->value => $form->label()])->all();
    }

    /**
     * The suggested units for this form.
     *
     * @return array{base_unit: string, whole_units_only: bool, allow_unit_sale: bool, packs: list<array{name: string, factor: int}>}
     */
    public function unitDefaults(): array
    {
        /** @var array{base_unit?: string, whole_units_only?: bool, allow_unit_sale?: bool, packs?: list<array{name: string, factor: int}>} $defaults */
        $defaults = config("pos.pharmacy.unit_defaults.{$this->value}", []);

        return [
            'base_unit' => $defaults['base_unit'] ?? 'Piece',
            'whole_units_only' => $defaults['whole_units_only'] ?? true,
            'allow_unit_sale' => $defaults['allow_unit_sale'] ?? true,
            'packs' => $defaults['packs'] ?? [],
        ];
    }
}
