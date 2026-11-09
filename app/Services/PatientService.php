<?php

namespace App\Services;

use App\Exceptions\PosException;
use App\Models\Device;
use App\Models\Patient;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Services\Data\PatientInput;
use App\Support\CurrentShop;
use App\Support\PatientAge;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Patients (docs/PHARMACY.md, branch 13): one record per person, shared by
 * every till and location, found by name at the till or added there with
 * name and age (online or offline: the till makes the uuid). A sale keeps
 * the patient and a snapshot of their name and age at the visit, so the
 * register stays right when the patient is edited later.
 */
class PatientService
{
    /** What the till's search shows at most. */
    public const SEARCH_LIMIT = 10;

    public function __construct(private readonly CurrentShop $currentShop) {}

    /** The visit's day in the shop's time zone (an age is worked out on it). */
    public static function visitDay(Shop $shop, ?\DateTimeInterface $at = null): CarbonImmutable
    {
        return CarbonImmutable::parse($at ?? now())->setTimezone($shop->timezone ?: config('app.timezone'))->startOfDay();
    }

    /** A name as typed: spaces trimmed and runs of them made one; blank = none. */
    public static function cleanName(?string $name): ?string
    {
        $name = preg_replace('/\s+/u', ' ', trim((string) $name)) ?? '';

        return $name === '' ? null : mb_substr($name, 0, 255);
    }

    public function find(string $uuid): ?Patient
    {
        return Patient::query()->where('uuid', strtolower($uuid))->first();
    }

    /**
     * The patient of a sale and what the sale keeps of them, inside the
     * checkout's transaction. A uuid the shop has is that patient: online
     * their name is the record's (the till cannot rename them); offline the
     * name the till printed is kept. An age typed at the till is the age at
     * the visit, and re-estimates a date of birth that was only ever
     * estimated. A new uuid with a name is a new patient, created once even
     * when two uploads race (unique uuid).
     *
     * @return array{patient: Patient, name: string, age_months: int|null}|null null: an unknown uuid without a name (refused online by CheckoutService first)
     */
    public function forSale(PatientInput $input, \DateTimeInterface $soldAt, Device $device, User $cashier, bool $offline): ?array
    {
        $shop = $this->currentShop->getOrFail();
        $day = self::visitDay($shop, $soldAt);
        $patient = $this->find($input->uuid);
        $typedName = self::cleanName($input->name);

        if ($patient === null) {
            if ($typedName === null) {
                return null;
            }

            $patient = Patient::query()->createOrFirst(['uuid' => strtolower($input->uuid)], [
                'name' => $typedName,
                'birth_date' => $input->ageMonths === null ? null : PatientAge::birthDate($input->ageMonths, $day)->toDateString(),
                'birth_date_estimated' => $input->ageMonths !== null,
                'created_by' => $cashier->getKey(),
                'device_id' => $device->getKey(),
            ]);
        } elseif ($input->ageMonths !== null && ($patient->birth_date === null || $patient->birth_date_estimated)
            && PatientAge::at($patient->birth_date, $day) !== $input->ageMonths) {
            $patient->forceFill([
                'birth_date' => PatientAge::birthDate($input->ageMonths, $day)->toDateString(),
                'birth_date_estimated' => true,
            ])->save();
        }

        return [
            'patient' => $patient,
            'name' => $offline && $typedName !== null ? $typedName : $patient->name,
            'age_months' => $input->ageMonths ?? PatientAge::at($patient->birth_date, $day),
        ];
    }

    /**
     * The till's search: names containing every word typed, most recent
     * visitors first.
     *
     * @return list<array{uuid: string, name: string, age_months: int|null, age_on: string, last_visit: string|null}>
     */
    public function search(string $term): array
    {
        if (mb_strlen(trim($term)) < 2) {
            return [];
        }

        $shop = $this->currentShop->getOrFail();

        return array_values(Patient::query()
            ->search($term)
            ->withMax('sales as last_visit_at', 'sold_at')
            ->orderByDesc('last_visit_at')
            ->orderBy('name')
            ->limit(self::SEARCH_LIMIT)
            ->get()
            ->map(fn (Patient $patient): array => [
                ...$this->forTill($patient, $shop),
                'last_visit' => $patient->getAttribute('last_visit_at') === null
                    ? null
                    : self::visitDay($shop, CarbonImmutable::parse((string) $patient->getAttribute('last_visit_at')))->toDateString(),
            ])
            ->all());
    }

    /**
     * What a till keeps of a patient: name and age only, never the notes
     * (decided 2026-11-10). The age is today's; the till ages it by
     * age_on (the day it was worked out).
     *
     * @return array{uuid: string, name: string, age_months: int|null, age_on: string}
     */
    public function forTill(Patient $patient, Shop $shop): array
    {
        $day = self::visitDay($shop);

        return [
            'uuid' => $patient->uuid,
            'name' => $patient->name,
            'age_months' => PatientAge::at($patient->birth_date, $day),
            'age_on' => $day->toDateString(),
        ];
    }

    /**
     * Change a patient (patients.manage): name, notes, and either a date of
     * birth or an age today (which estimates one). Bills keep what they
     * snapshotted. Audited (Patient is an audited model).
     *
     * @param  array{name: string, birth_date?: string|null, age?: string|null, notes?: string|null}  $data
     */
    public function update(Patient $patient, User $user, array $data): Patient
    {
        if (! $user->can('patients.manage')) {
            throw new PosException('You do not have permission to change patients.');
        }

        $name = self::cleanName($data['name']) ?? throw new PosException("Enter the patient's name.");

        $attributes = ['name' => $name, 'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null];

        if (filled($data['birth_date'] ?? null)) {
            $birthDate = CarbonImmutable::parse((string) $data['birth_date']);

            if ($birthDate->isAfter(self::visitDay($this->currentShop->getOrFail()))) {
                throw new PosException('The date of birth cannot be in the future.');
            }

            $attributes += ['birth_date' => $birthDate->toDateString(), 'birth_date_estimated' => false];
        } elseif (filled($data['age'] ?? null)) {
            $months = PatientAge::parse((string) $data['age']) ?? throw new PosException('Enter the age in years (34) or months (8m).');
            $attributes += ['birth_date' => PatientAge::birthDate($months, self::visitDay($this->currentShop->getOrFail()))->toDateString(), 'birth_date_estimated' => true];
        }

        return DB::transaction(function () use ($patient, $attributes): Patient {
            $patient->fill($attributes)->save();

            return $patient;
        });
    }

    /**
     * Every bill of the patient, OPD and pharmacy, at every location, newest
     * first.
     *
     * @return Builder<Sale>
     */
    public function history(Patient $patient): Builder
    {
        return Sale::query()->where('patient_id', $patient->getKey())->orderByDesc('sold_at')->orderByDesc('id');
    }
}
