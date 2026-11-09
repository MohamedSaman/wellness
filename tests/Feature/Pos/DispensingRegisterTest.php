<?php

namespace Tests\Feature\Pos;

use App\Enums\BillType;
use App\Enums\DrugSchedule;
use App\Enums\PaymentMethod;
use App\Filament\Pages\Reports\DispensingRegisterReport;
use App\Filament\Pages\Reports\ReportRegistry;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Patients\RelationManagers\SalesRelationManager;
use App\Filament\Resources\Sales\SaleResource;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Patient;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PatientInput;
use App\Services\Data\PaymentInput;
use App\Services\ReportService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * The dispensing register and the patients' pages (docs/PHARMACY.md,
 * branch 13): which patient received which medicine, per batch, from the
 * bills' own rows, on the shop's days; bills without a patient say so;
 * voided bills and services are left out. One patient's OPD and pharmacy
 * bills at both locations on their page. dispensing_register.view (the
 * owner) for both; every view and export audited. The clock is at
 * 2026-10-18 10:00 in Colombo.
 */
class DispensingRegisterTest extends BatchTestCase
{
    use WorksWithCheckout;

    private ProductVariant $panadol;

    private ProductVariant $amox;

    private ProductVariant $morphine;

    private Device $doc;

    private User $doctor;

    private string $nimal;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->setShopSetting('rx_prescriber', 'prompt');
        $this->panadol = $this->medicine();
        $this->amox = $this->medicine('Amoxicillin 250 mg Capsules', [], schedule: DrugSchedule::PrescriptionOnly);
        $this->morphine = $this->medicine('Morphine 10 mg Tablets', [], schedule: DrugSchedule::Controlled);
        $this->doc = $this->docTill();
        $this->doctor = $this->cashierAt($this->doctors);
        $this->nimal = (string) Str::uuid();

        $this->receive($this->panadol, 'P1', '03/2027', 100);
        $this->receive($this->amox, 'A1', '11/2026', 5);
        $this->receive($this->amox, 'A2', '02/2027', 50);
        $this->receive($this->amox, 'D1', '06/2027', 50, $this->doctors);
        $this->receive($this->morphine, 'M1', '12/2027', 20);
    }

    /** @param  list<CartLine>  $lines */
    private function ring(array $lines, ?PatientInput $patient = null, ?Device $till = null, ?User $cashier = null, BillType $billType = BillType::Standard, ?int $medicinesAmount = null): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $till ?? $this->till,
            cashier: $cashier ?? $this->cashier,
            lines: $lines,
            payments: [new PaymentInput(PaymentMethod::Cash, 10_000_000)],
            uuid: (string) Str::uuid(),
            billType: $billType,
            opdMedicinesAmount: $medicinesAmount,
            patient: $patient,
        ));
    }

    /** Today: Nimal at General Pharmacy and at the OPD, a bill without a patient, a voided bill; yesterday late evening, Kamala. */
    private function aDay(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-17 23:30', 'Asia/Colombo'));
        $this->ring([new CartLine($this->panadol->getKey(), 3)], new PatientInput((string) Str::uuid(), 'Kamala Perera', 732));

        $this->travelTo(CarbonImmutable::parse('2026-10-18 00:10', 'Asia/Colombo'));
        $this->ring([
            new CartLine($this->panadol->getKey(), 2),
            new CartLine($this->amox->getKey(), 15, prescriber: 'Dr. S. Perera', prescriptionRef: 'RX-1042'),
        ], new PatientInput($this->nimal, 'Nimal Perera', 408));

        $this->travelTo(CarbonImmutable::parse('2026-10-18 09:00', 'Asia/Colombo'));
        $this->ring([
            new CartLine($this->consultation()->getKey(), 1),
            new CartLine($this->amox->getKey(), 10, prescriber: 'Dr. S. Perera'),
        ], new PatientInput($this->nimal), $this->doc, $this->doctor, BillType::Opd, 85_000);

        $this->travelTo(CarbonImmutable::parse('2026-10-18 09:30', 'Asia/Colombo'));
        $this->ring([new CartLine($this->morphine->getKey(), 1, prescriber: 'Dr. S. Perera')]);
        $voided = $this->ring([new CartLine($this->panadol->getKey(), 1)], new PatientInput((string) Str::uuid(), 'Voided Patient', 400));
        app(CheckoutService::class)->void($voided, $this->owner, 'Rang up twice');

        $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Colombo'));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function rows(?ReportPeriod $period = null, array $filters = []): array
    {
        return iterator_to_array(app(ReportService::class)->dispensingRegisterRows($period ?? ReportPeriod::today($this->shop), $filters), false);
    }

    public function test_the_register_lists_every_medicine_batch_with_its_patient_or_none(): void
    {
        $this->aDay();
        $rows = $this->rows();

        $this->assertSame([
            ['00:10', 'T1', 'General Pharmacy', 'Nimal Perera', '34 y', 'Panadol 500 mg Tablets', 'Tablet', 'Over the counter', 'P1', '03/2027', 2.0, 'Tablet', null],
            ['00:10', 'T1', 'General Pharmacy', 'Nimal Perera', '34 y', 'Amoxicillin 250 mg Capsules', 'Tablet', 'Prescription only', 'A1', '11/2026', 5.0, 'Tablet', 'Dr. S. Perera'],
            ['00:10', 'T1', 'General Pharmacy', 'Nimal Perera', '34 y', 'Amoxicillin 250 mg Capsules', 'Tablet', 'Prescription only', 'A2', '02/2027', 10.0, 'Tablet', 'Dr. S. Perera'],
            ['09:00', 'T2', "Doctor's Medicines", 'Nimal Perera', '34 y', 'Amoxicillin 250 mg Capsules', 'Tablet', 'Prescription only', 'D1', '06/2027', 10.0, 'Tablet', 'Dr. S. Perera'],
            ['09:30', 'T1', 'General Pharmacy', 'No patient recorded', null, 'Morphine 10 mg Tablets', 'Tablet', 'Controlled', 'M1', '12/2027', 1.0, 'Tablet', 'Dr. S. Perera'],
        ], array_map(fn (array $row): array => [
            substr((string) $row['when'], 11), $row['till'], $row['location'], $row['patient'], $row['age'], $row['medicine'], $row['form'],
            $row['schedule'], $row['batch'], $row['expiry'], $row['quantity'], $row['unit'], $row['prescriber'],
        ], $rows));

        $this->assertSame('2026-10-18 00:10', $rows[0]['when']);
        $this->assertSame('RX-1042', $rows[1]['rx_ref']);
        $this->assertSame([$this->cashier->name, $this->doctor->name], array_values(array_unique(array_column(array_slice($rows, 0, 4), 'cashier'))));
        $this->assertSame(0.0, $rows[0]['returned']);
    }

    public function test_the_register_filters_by_the_shops_days_patient_medicine_schedule_and_location(): void
    {
        $this->aDay();
        $nimal = Patient::query()->where('uuid', $this->nimal)->sole();

        // Yesterday 23:30 in Colombo is yesterday, whatever UTC says.
        $yesterday = $this->rows(ReportPeriod::yesterday($this->shop));
        $this->assertSame([['Kamala Perera', '61 y', 3.0]], array_map(fn (array $row): array => [$row['patient'], $row['age'], $row['quantity']], $yesterday));
        $this->assertSame('2026-10-17 23:30', $yesterday[0]['when']);

        $this->assertCount(4, $this->rows(filters: ['patient' => $nimal->getKey()]));
        $this->assertSame(['No patient recorded'], array_column($this->rows(filters: ['patient' => 'none']), 'patient'));
        $this->assertSame(['A1', 'A2', 'D1'], array_column($this->rows(filters: ['variant_id' => $this->amox->getKey()]), 'batch'));
        $this->assertSame(['M1'], array_column($this->rows(filters: ['schedule' => 'controlled']), 'batch'));
        $this->assertSame(['D1'], array_column($this->rows(filters: ['location_id' => $this->doctors->getKey()]), 'batch'));
        $this->assertSame(5, app(ReportService::class)->dispensingRegister(ReportPeriod::today($this->shop), [], 1, 2)->total());
    }

    public function test_the_register_page_and_its_exports_are_audited_with_their_filters(): void
    {
        $this->aDay();
        $this->actingAs($this->owner);
        $audit = fn (string $event) => AuditLog::query()->where('event', $event)->orderBy('id');

        Livewire::withQueryParams(['range' => 'today'])->test(DispensingRegisterReport::class)
            ->assertSee('Nimal Perera')
            ->assertSee('No patient recorded')
            ->assertDontSee('Voided Patient')
            ->set('schedule', 'controlled');

        // Opening it, then the change of filter.
        $views = $audit(DispensingRegisterReport::VIEWED_EVENT)->get();
        $this->assertCount(2, $views);
        $this->assertSame($this->owner->getKey(), (int) $views[0]->user_id);
        $this->assertSameIgnoringKeyOrder(['from' => '2026-10-18', 'to' => '2026-10-18'], $views[0]->new_values);
        $this->assertSameIgnoringKeyOrder(['from' => '2026-10-18', 'to' => '2026-10-18', 'schedule' => 'controlled'], $views[1]->new_values);

        $component = Livewire::withQueryParams(['range' => 'today', 'schedule' => 'prescription_only'])->test(DispensingRegisterReport::class);
        $component->callTableAction('exportXlsx')->assertFileDownloaded();
        $component->callTableAction('exportPdf')->assertFileDownloaded();
        $component->call('exportCsv');
        $this->get(route('reports.print', ['report' => DispensingRegisterReport::getSlug(), 'range' => 'today', 'patient' => 'none']))
            ->assertSuccessful()
            ->assertSee('No patient recorded')
            ->assertDontSee('Nimal Perera');

        $exports = $audit(DispensingRegisterReport::EXPORTED_EVENT)->get();
        $this->assertSame(['xlsx', 'pdf', 'csv', 'print'], $exports->map(fn (AuditLog $log): string => $log->new_values['format'])->all());
        $this->assertSame(['prescription_only', 'prescription_only', 'prescription_only', null], $exports->map(fn (AuditLog $log): ?string => $log->new_values['schedule'] ?? null)->all());
        $this->assertSame([3, 3, null, 1], $exports->map(fn (AuditLog $log): ?int => $log->new_values['rows'] ?? null)->all());
        $this->assertSame('none', $exports[3]->new_values['patient']);
    }

    public function test_only_dispensing_register_view_opens_the_register_and_the_patients(): void
    {
        $this->aDay();
        $patient = Patient::query()->where('uuid', $this->nimal)->sole();
        $this->assertContains(DispensingRegisterReport::class, ReportRegistry::PAGES);

        // A cashier, and someone with every report but not the register.
        $this->actingAs($this->cashier);
        $this->assertFalse(DispensingRegisterReport::canAccess());
        $this->cashier->givePermissionTo(['admin.access', 'reports.view', 'reports.profit']);
        $this->get(DispensingRegisterReport::getUrl())->assertForbidden();
        $this->get(route('reports.print', ['report' => DispensingRegisterReport::getSlug()]))->assertForbidden();
        $this->get(PatientResource::getUrl('index'))->assertForbidden();
        $this->get(PatientResource::getUrl('view', ['record' => $patient]))->assertForbidden();
        $this->assertSame(0, AuditLog::query()->where('event', DispensingRegisterReport::VIEWED_EVENT)->count());

        $this->cashier->givePermissionTo('dispensing_register.view');
        $this->get(DispensingRegisterReport::getUrl())->assertSuccessful();
        $this->get(PatientResource::getUrl('view', ['record' => $patient]))->assertSuccessful();

        // The owner has it ('*'); without the pharmacy module there is no register.
        $this->actingAs($this->owner);
        $this->assertTrue(DispensingRegisterReport::canAccess());
        Feature::for($this->shop)->deactivate('module:pharmacy');
        $this->assertFalse(DispensingRegisterReport::canAccess());
        $this->assertFalse(PatientResource::canAccess());
    }

    public function test_a_patients_page_shows_their_opd_and_pharmacy_bills_and_is_audited(): void
    {
        $this->aDay();
        $patient = Patient::query()->where('uuid', $this->nimal)->sole();
        $this->actingAs($this->owner);

        Livewire::test(ListPatients::class)
            ->assertCanSeeTableRecords([$patient])
            ->searchTable('perera nim')
            ->assertCanSeeTableRecords([$patient])
            ->assertCanNotSeeTableRecords(Patient::query()->where('name', 'Kamala Perera')->get());

        $this->get(PatientResource::getUrl('view', ['record' => $patient]))->assertSuccessful()->assertSee('Nimal Perera');
        $viewed = AuditLog::query()->where('event', ViewPatient::VIEWED_EVENT)->sole();
        $this->assertSame($patient->getKey(), (int) $viewed->auditable_id);
        $this->assertSame($this->owner->getKey(), (int) $viewed->user_id);

        // The sale page names the patient, with the age at that visit and a link to their page.
        $this->get(SaleResource::getUrl('view', ['record' => $patient->sales()->firstOrFail()]))
            ->assertSuccessful()
            ->assertSee('Nimal Perera · 34 y')
            ->assertSee(PatientResource::getUrl('view', ['record' => $patient]), false);

        $sales = $patient->sales()->orderBy('sold_at')->get();
        $this->assertSame([BillType::Standard, BillType::Opd], $sales->pluck('bill_type')->all());
        $this->assertSame([$this->general->getKey(), $this->doctors->getKey()], $sales->pluck('location_id')->all());

        Livewire::test(SalesRelationManager::class, ['ownerRecord' => $patient, 'pageClass' => ViewPatient::class])
            ->assertCanSeeTableRecords($sales)
            ->assertSee('OPD')
            ->assertSee('Pharmacy')
            ->assertSee('Amoxicillin 250 mg Capsules × 15')
            ->assertSee('Dr. S. Perera')
            ->filterTable('bill_type', 'opd')
            ->assertCanSeeTableRecords([$sales[1]])
            ->assertCanNotSeeTableRecords([$sales[0]]);
    }

    public function test_the_owner_edits_a_patient_and_the_bills_keep_what_they_recorded(): void
    {
        $this->aDay();
        $patient = Patient::query()->where('uuid', $this->nimal)->sole();
        $this->actingAs($this->owner);

        Livewire::test(ViewPatient::class, ['record' => $patient->uuid])
            ->callAction('edit', ['name' => 'Nimal K. Perera', 'birth_date' => '1990-01-05', 'age' => null, 'notes' => 'Allergic to penicillin'])
            ->assertHasNoActionErrors()
            ->assertNotified('Patient saved');

        $patient->refresh();
        $this->assertSame(['Nimal K. Perera', '1990-01-05', false, 'Allergic to penicillin'], [$patient->name, $patient->birth_date?->toDateString(), $patient->birth_date_estimated, $patient->notes]);
        $this->assertSame(['Nimal Perera', 'Nimal Perera'], $patient->sales()->pluck('customer_name')->all());
        $this->assertSame(1, AuditLog::query()->where('event', 'updated')->where('auditable_type', $patient->getMorphClass())->where('auditable_id', $patient->getKey())->count());

        // Without patients.manage: no Edit.
        $this->cashier->givePermissionTo(['admin.access', 'dispensing_register.view']);
        $this->actingAs($this->cashier);
        Livewire::test(ViewPatient::class, ['record' => $patient->uuid])->assertActionHidden('edit');
    }
}
