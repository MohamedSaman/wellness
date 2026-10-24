<?php

namespace Tests\Feature\Pos;

use App\Enums\SupplierPaymentMethod;
use App\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Models\ProductVariant;
use App\Services\SupplierService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Feature\Pos\Concerns\WorksWithPurchasing;

/**
 * The supplier statement as Excel and PDF (docs/PHARMACY.md §9, branch 11):
 * the same lines and closing balance as the printed statement, which equals
 * the supplier's balance; whoever may read the statement may export it.
 */
class SupplierStatementExportTest extends BatchTestCase
{
    use WorksWithPurchasing;

    private ProductVariant $panadol;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->panadol = $this->medicine();
    }

    /** @return list<list<mixed>> the first sheet's rows */
    private function rows(string $xlsx): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $xlsx);
        $reader = new Reader;
        $reader->open($path);
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }

            break;
        }

        $reader->close();
        unlink($path);

        return $rows;
    }

    public function test_the_statement_exports_to_excel_and_pdf_with_the_suppliers_balance(): void
    {
        $hemas = $this->supplier();
        $this->bill($hemas, $this->panadol, 100_000, 'H-1', receivedOn: '2026-10-02');
        $this->bill($hemas, $this->panadol, 70_000, 'H-2', receivedOn: '2026-10-10');
        app(SupplierService::class)->recordPayment($hemas, 30_000, SupplierPaymentMethod::BankTransfer, $this->owner, reference: 'TRF 9');
        $balance = app(SupplierService::class)->balance($hemas)['balance'];
        $this->assertSame(140_000, $balance);

        $this->actingAs($this->owner);
        $query = ['supplier' => $hemas, 'from' => '2026-10-01', 'to' => '2026-10-18'];

        $xlsx = $this->get(route('purchasing.print.statement', [...$query, 'format' => 'xlsx']))
            ->assertOk()
            ->assertDownload('supplier-statement_hemas-pharmaceuticals_2026-10-01_2026-10-18.xlsx')
            ->streamedContent();
        $rows = $this->rows($xlsx);
        $balanceRow = collect($rows)->first(fn (array $row): bool => ($row[0] ?? null) === 'Balance at 18/10/2026');
        $this->assertNotNull($balanceRow);
        // Owed, paid, balance in rupees.
        $this->assertEquals([1_700, 300, 1_400], [$balanceRow[4], $balanceRow[5], $balanceRow[6]]);
        $this->assertContains(['Supplier', 'Hemas Pharmaceuticals'], array_map(fn (array $row): array => array_slice($row, 0, 2), $rows));

        $pdf = $this->get(route('purchasing.print.statement', [...$query, 'format' => 'pdf']))->assertOk()->streamedContent();
        $this->assertStringStartsWith('%PDF-', $pdf);

        // From the supplier page: the PDF or Excel opens in a new tab.
        Livewire::test(ViewSupplier::class, ['record' => $hemas->getRouteKey()])
            ->callAction('statement', data: ['from' => '2026-10-01', 'to' => '2026-10-18', 'format' => 'xlsx'])
            ->assertHasNoActionErrors();

        $this->actingAs($this->cashier)->get(route('purchasing.print.statement', [...$query, 'format' => 'xlsx']))->assertForbidden();
    }
}
