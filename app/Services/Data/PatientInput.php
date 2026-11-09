<?php

namespace App\Services\Data;

use App\Services\PatientService;

/**
 * The patient a till names on a sale (docs/PHARMACY.md, branch 13): one it
 * found (its uuid), or one it added, online or offline (a new uuid, the
 * name and the age typed). The till makes the uuid, so a patient added
 * offline is created once however many of their sales reach the server.
 */
final readonly class PatientInput
{
    public function __construct(
        public string $uuid,
        public ?string $name = null,
        public ?int $ageMonths = null,   // as typed at the till: "34" = 408, "8m" = 8
    ) {}

    /** @param  array<string, mixed>|null  $input  validated with rules() */
    public static function fromArray(?array $input): ?self
    {
        if ($input === null || ! is_string($input['uuid'] ?? null)) {
            return null;
        }

        $age = $input['age_months'] ?? null;
        $name = $input['name'] ?? null;

        return new self(
            strtolower($input['uuid']),
            PatientService::cleanName(is_string($name) ? $name : null),
            is_numeric($age) ? (int) $age : null,
        );
    }

    /** @return array<string, mixed> validation rules for `patient` on a sale */
    public static function rules(): array
    {
        return [
            'patient' => ['nullable', 'array'],
            'patient.uuid' => ['required_with:patient', 'uuid'],
            'patient.name' => ['nullable', 'string', 'max:255'],
            'patient.age_months' => ['nullable', 'integer', 'min:0', 'max:1800'],
        ];
    }
}
