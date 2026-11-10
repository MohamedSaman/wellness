<?php

namespace Database\Seeders;

use App\Models\Patient;
use App\Models\Shop;
use App\Services\PatientService;
use App\Support\CurrentShop;
use App\Support\PatientAge;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * LOCAL DEVELOPMENT ONLY: a few patients (docs/PHARMACY.md, branch 13), so
 * the till's patient search finds someone at :8001 and in the e2e tests.
 * Runs after the other demo clinic seeders (DemoClinicSeeder calls it
 * outside tests). Ages as told at a visit today (dates of birth estimated),
 * one baby in months, one with a date of birth on file, one without an age.
 */
class DemoClinicPatientsSeeder extends Seeder
{
    public function run(CurrentShop $currentShop): void
    {
        $shop = Shop::query()->where('code', 'CLINIC')->firstOrFail();

        $currentShop->runAs($shop, function () use ($shop): void {
            $today = PatientService::visitDay($shop);

            foreach ([
                ['Nimal Perera', '34'],
                ['Kamala Perera', '61'],
                ['Sunil Fernando', '47'],
                ['Baby Dilini Silva', '8m'],
                ['Ayesha Mohamed', null, '1990-03-14'],
                ['Ruwan Jayasinghe', null],
            ] as $row) {
                [$name, $age] = $row;
                $months = PatientAge::parse($age);

                Patient::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'name' => $name,
                    'birth_date' => $row[2] ?? ($months === null ? null : PatientAge::birthDate($months, $today)->toDateString()),
                    'birth_date_estimated' => ! isset($row[2]) && $months !== null,
                ]);
            }
        });
    }
}
