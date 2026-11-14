<?php

namespace Tests\Feature\Pos\Concerns;

use App\Enums\BillType;
use App\Enums\PaymentMethod;
use App\Models\Device;
use App\Models\Patient;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PatientInput;
use App\Services\Data\PaymentInput;
use App\Services\ShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * A clinic's visits for the patient filters and shift lists
 * (docs/PHARMACY.md, branch 16), in Colombo (UTC+5:30), ending at
 * 2026-10-18 10:00 (a Sunday; the week began on Monday the 12th):
 *
 * - Sunil: 2026-09-20 at General Pharmacy (last month only).
 * - Nimal: 2026-10-01 at General Pharmacy; today 00:10 at General Pharmacy
 *   (T1, shift B) and 09:00 an OPD bill at Doctor's Medicines (T2, shift C).
 * - Kamala: yesterday 23:30 at General Pharmacy (T1, shift A), which is
 *   the same UTC day as Nimal's 00:10.
 * - Rani: today 09:30 at T1 (shift B), voided: no visit.
 * - Zara: added, never billed.
 *
 * Shift A (T1, 22:00–23:50 yesterday) and C (T2, 08:00–09:15) are closed;
 * B (T1, from 00:05) is open.
 *
 * Use with PharmacyTestCase and WorksWithCheckout.
 */
trait WorksWithPatientVisits
{
    protected Device $doc;

    protected User $doctor;

    protected ProductVariant $labTest;

    protected ProductVariant $consult;

    protected Shift $shiftA;

    protected Shift $shiftB;

    protected Shift $shiftC;

    /** @var array<string, Patient> */
    protected array $patients = [];

    /** @var array<string, Sale> */
    protected array $bills = [];

    protected function seedVisits(): void
    {
        $this->doc = $this->docTill();
        $this->doctor = $this->cashierAt($this->doctors);
        $this->labTest = $this->openPriceItem('CBS', 50_000, 100_000);
        $this->consult = $this->consultation();
        $shifts = app(ShiftService::class);

        $this->at('2026-09-20 11:00');
        $this->bills['sunil'] = $this->bill('Sunil Fernando', 600);

        $this->at('2026-10-01 10:00');
        $this->bills['nimal_oct'] = $this->bill('Nimal Perera', 408);

        $this->at('2026-10-17 22:00');
        $this->shiftA = $shifts->open($this->till, $this->cashier, 0);
        $this->at('2026-10-17 23:30');
        $this->bills['kamala'] = $this->bill('Kamala Perera', 732);
        $this->at('2026-10-17 23:50');
        $shifts->close($this->shiftA, $this->cashier, 50_000);

        $this->at('2026-10-18 00:05');
        $this->shiftB = $shifts->open($this->till, $this->cashier, 0);
        $this->at('2026-10-18 00:10');
        $this->bills['nimal_gen'] = $this->bill('Nimal Perera');

        $this->at('2026-10-18 08:00');
        $this->shiftC = $shifts->open($this->doc, $this->doctor, 0);
        $this->at('2026-10-18 09:00');
        $this->bills['nimal_opd'] = $this->bill('Nimal Perera', till: $this->doc, cashier: $this->doctor, opd: true);
        $this->at('2026-10-18 09:15');
        $shifts->close($this->shiftC, $this->doctor, 0);

        $this->at('2026-10-18 09:30');
        $this->bills['rani'] = $this->bill('Rani Silva', 300);
        app(CheckoutService::class)->void($this->bills['rani'], $this->owner, 'Rang up twice');

        $this->at('2026-10-18 10:00');
        $this->patients['zara'] = Patient::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Zara Khan']);

        $this->shiftA->refresh();
        $this->shiftB->refresh();
        $this->shiftC->refresh();
    }

    protected function at(string $local): void
    {
        $this->travelTo(CarbonImmutable::parse($local, 'Asia/Colombo'));
    }

    /** A bill for the patient named (found by name once added), a lab test or (OPD) the consultation. */
    protected function bill(string $name, ?int $ageMonths = null, ?Device $till = null, ?User $cashier = null, bool $opd = false): Sale
    {
        $key = strtolower(strtok($name, ' ') ?: $name);
        $patient = $this->patients[$key] ?? null;

        $sale = app(CheckoutService::class)->checkout(new CheckoutData(
            device: $till ?? $this->till,
            cashier: $cashier ?? $this->cashier,
            lines: [new CartLine(($opd ? $this->consult : $this->labTest)->getKey(), 1)],
            payments: [new PaymentInput(PaymentMethod::Cash, 1_000_000)],
            uuid: (string) Str::uuid(),
            billType: $opd ? BillType::Opd : BillType::Standard,
            opdMedicinesAmount: $opd ? 0 : null,
            patient: $patient === null ? new PatientInput((string) Str::uuid(), $name, $ageMonths) : new PatientInput($patient->uuid),
        ));

        $this->patients[$key] ??= $sale->patient()->firstOrFail();

        return $sale;
    }
}
