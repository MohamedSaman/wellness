<?php

namespace Tests\Feature\Pos;

use App\Enums\AdjustmentReason;
use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Filament\Pages\Reports\LowStockReport;
use App\Filament\Pages\Reports\PaymentMethodsReport;
use App\Filament\Pages\Reports\ProfitByProductReport;
use App\Filament\Pages\Reports\ReportPage;
use App\Filament\Pages\Reports\SalesByBrandReport;
use App\Filament\Pages\Reports\SalesByCashierReport;
use App\Filament\Pages\Reports\SalesByCategoryReport;
use App\Filament\Pages\Reports\SalesByDayReport;
use App\Filament\Pages\Reports\ShiftHistoryReport;
use App\Filament\Pages\Reports\StockMovementsReport;
use App\Filament\Pages\Reports\StockValuationReport;
use App\Filament\Pages\Reports\VoidsAndReturnsReport;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\PaymentInput;
use App\Services\ReturnService;
use App\Services\ShiftService;
use App\Services\StockService;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every report page and its CSV export, run with data that gives every report
 * rows (voids, returns that are and are not restocked, adjustments, shifts ...),
 * so a report query that breaks on a database fails CI.
 */
class ReportPagesSmokeTest extends AdminTestCase
{
    /** @return array<string, array{0: class-string<ReportPage>}> */
    public static function reports(): array
    {
        return [
            'sales by day' => [SalesByDayReport::class],
            'sales by cashier' => [SalesByCashierReport::class],
            'sales by category' => [SalesByCategoryReport::class],
            'sales by brand' => [SalesByBrandReport::class],
            'payment methods' => [PaymentMethodsReport::class],
            'voids and returns' => [VoidsAndReturnsReport::class],
            'shift history' => [ShiftHistoryReport::class],
            'stock valuation' => [StockValuationReport::class],
            'low stock' => [LowStockReport::class],
            'stock movements' => [StockMovementsReport::class],
            'profit by product' => [ProfitByProductReport::class],
        ];
    }

    private function aBusyDay(): void
    {
        $checkout = app(CheckoutService::class);
        $returns = app(ReturnService::class);
        $shifts = app(ShiftService::class);
        $plug = $this->variant('CPR8EA-9')->getKey();
        $oil = $this->variant('MOTUL')->getKey();

        $shift = $shifts->open($this->till, $this->cashier, 500_000);

        $cash = $checkout->checkout($this->cart([new CartLine($plug, 3)], [new PaymentInput(PaymentMethod::Cash, 500_000)]));
        $checkout->checkout($this->cart([new CartLine($oil, 1)], [new PaymentInput(PaymentMethod::Card, 320_000)]));
        $checkout->checkout($this->cart([new CartLine($plug, 1), new CartLine($oil, 1)], [
            new PaymentInput(PaymentMethod::Cash, 100_000),
            new PaymentInput(PaymentMethod::BankTransfer, 365_000),
        ]));
        $voided = $checkout->checkout($this->cart([new CartLine($plug, 1)]));
        $checkout->void($voided, $this->owner, 'Rung up twice');

        $returns->process($cash, [$cash->items->first()->getKey() => 1], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);
        $returns->process($cash, [$cash->items->first()->getKey() => 1], ReturnReason::FaultyDamaged, PaymentMethod::Cash, $this->owner, restock: false, note: 'Cracked');

        app(StockService::class)->adjustTo($this->variant('MOTUL'), $this->location, 20, AdjustmentReason::Expired, $this->owner);
        app(StockService::class)->stockIn($this->variant('MOTUL'), $this->location, 6, $this->owner, unitCost: 2_400, note: 'Delivery 42');
        $this->variant('CPR8EA-9')->update(['reorder_level' => 100]);

        $shifts->close($shift, $this->cashier, 600_000, 'Counted twice');
    }

    /** @param class-string<ReportPage> $report */
    #[DataProvider('reports')]
    public function test_the_report_page_and_its_csv_work_with_real_data(string $report): void
    {
        $this->aBusyDay();
        $this->actingAs($this->owner);

        $this->get($report::getUrl(panel: 'admin'))->assertSuccessful();

        $download = data_get(
            Livewire::test($report)->callTableAction('export')->assertFileDownloaded()->effects,
            'download',
        );
        $csv = array_values(array_filter(explode("\n", base64_decode((string) ($download['content'] ?? '')))));

        $this->assertNotEmpty($csv, 'The CSV is empty.');
        $this->assertStringNotContainsString('SQLSTATE', $csv[0]);
        $this->assertGreaterThanOrEqual(2, count($csv), "{$report} exported a header but no rows.");
    }
}
