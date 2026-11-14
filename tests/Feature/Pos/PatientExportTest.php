<?php

namespace Tests\Feature\Pos;

use App\Exceptions\PosException;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Models\AuditLog;
use App\Services\Data\PatientFilters;
use App\Services\PatientListService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithPatientVisits;

/**
 * Excel and PDF of the filtered Patients list (docs/PHARMACY.md, branch
 * 16): patients.export, the owner's only by default; the rows are the
 * list's as filtered, searched and sorted; every export is written to the
 * audit log with its filters, format and number of rows.
 */
class PatientExportTest extends PharmacyTestCase
{
    use WorksWithCheckout;
    use WorksWithPatientVisits;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->seedVisits();
    }

    public function test_the_permission_is_the_owners_alone_by_default(): void
    {
        $this->assertTrue($this->owner->can('patients.export'));
        $this->assertFalse($this->cashier->can('patients.export'));
        $this->assertArrayHasKey('patients.export', config('pos.permissions'));
    }

    public function test_the_owner_exports_the_filtered_list_and_it_is_audited(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ListPatients::class)
            ->filterTable('last_visit', ['range' => 'this_month'])
            ->filterTable('bill_type', ['value' => 'pharmacy'])
            ->assertActionVisible('exportXlsx')
            ->callAction('exportXlsx')
            ->assertFileDownloaded('patients_2026-10-18.xlsx');

        $entry = AuditLog::query()->where('event', PatientListService::EXPORTED_EVENT)->sole();
        $this->assertSame($this->owner->getKey(), $entry->user_id);
        $this->assertSame($this->shop->getKey(), $entry->auditable_id);
        $this->assertSameIgnoringKeyOrder([
            'format' => 'xlsx',
            'rows' => 2,
            'filters' => ['Visited' => '01/10/2026 – 18/10/2026', 'Bill type' => 'Pharmacy'],
            'from' => '2026-10-01',
            'to' => '2026-10-18',
        ], $entry->new_values);

        Livewire::test(ListPatients::class)
            ->filterTable('shift', ['value' => 'open'])
            ->searchTable('nimal')
            ->callAction('exportPdf')
            ->assertFileDownloaded('patients_2026-10-18.pdf');

        $pdf = AuditLog::query()->where('event', PatientListService::EXPORTED_EVENT)->latest('id')->firstOrFail();
        $this->assertSame('pdf', $pdf->new_values['format']);
        $this->assertSame(1, $pdf->new_values['rows']);
        $this->assertSameIgnoringKeyOrder(['Shift' => 'Open shifts now', 'Search' => 'nimal'], $pdf->new_values['filters']);
    }

    public function test_the_rows_are_the_lists_with_its_columns_in_shop_time(): void
    {
        $service = app(PatientListService::class);
        $rows = $service->exportRows($service->query(new PatientFilters(minVisits: 1))->orderBy('name'), $this->owner);

        $this->assertSame([
            ['name' => 'Kamala Perera', 'age' => '61 y', 'visits' => 1, 'bills' => 1, 'first_visit' => '17/10/2026', 'last_visit' => '17/10/2026 23:30', 'last_location' => 'General Pharmacy'],
            ['name' => 'Nimal Perera', 'age' => '34 y', 'visits' => 3, 'bills' => 3, 'first_visit' => '01/10/2026', 'last_visit' => '18/10/2026 09:00', 'last_location' => "Doctor's Medicines"],
            ['name' => 'Sunil Fernando', 'age' => '50 y', 'visits' => 1, 'bills' => 1, 'first_visit' => '20/09/2026', 'last_visit' => '20/09/2026 11:00', 'last_location' => 'General Pharmacy'],
        ], $rows);
    }

    public function test_without_the_permission_there_is_no_export(): void
    {
        // May see patients, may not export them.
        $this->cashier->givePermissionTo(['admin.access', 'dispensing_register.view']);
        $this->actingAs($this->cashier->fresh());

        Livewire::test(ListPatients::class)
            ->assertActionHidden('exportXlsx')
            ->assertActionHidden('exportPdf');

        $this->assertSame(0, AuditLog::query()->where('event', PatientListService::EXPORTED_EVENT)->count());

        $service = app(PatientListService::class);
        $this->expectException(PosException::class);
        $service->exportRows($service->query(new PatientFilters), $this->cashier->fresh());
    }
}
