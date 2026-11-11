<?php

namespace Tests\Feature\Pos;

use App\Enums\BillType;
use App\Enums\PaymentMethod;
use App\Enums\StockMovementType;
use App\Filament\Pages\Reports\PriceChangesReport;
use App\Filament\Pages\Reports\ReportRegistry;
use App\Models\Device;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\ReportService;
use App\Services\StockService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * The "Price changes at till" report (docs/PHARMACY.md, branch 14): every
 * bill line charged a typed price other than its list price, from the sale
 * lines, newest first, on the shop's days; voided bills, lines at their list
 * price and OPD medicine lines left out. Filters: item, cashier, counter.
 * reports.view (the owner), pharmacy shops. The clock is at 2026-10-18 10:00
 * in Colombo.
 */
class PriceChangesReportTest extends BatchTestCase
{
    use WorksWithCheckout;

    private ProductVariant $dressing;

    private ProductVariant $cbs;

    private ProductVariant $fee;

    private Device $doc;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->dressing = $this->openPriceItem('Wound dressing', 50_000, max: 150_000, min: 20_000, tracksStock: true);
        app(StockService::class)->record($this->dressing, $this->general, StockMovementType::StockIn, 50, $this->owner, unitCost: 10_000);
        $this->cbs = $this->openPriceItem('CBS', 100_000, max: 300_000, ask: true);
        $this->fee = $this->consultation(150_000);
        $this->doc = $this->docTill();
        $this->doctor = $this->cashierAt($this->doctors);
    }

    /** @param  list<CartLine>  $lines */
    private function ring(array $lines, ?User $cashier = null, ?Device $till = null, BillType $billType = BillType::Standard, ?int $medicinesAmount = null): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $till ?? $this->till,
            cashier: $cashier ?? $this->cashier,
            lines: $lines,
            payments: [new PaymentInput(PaymentMethod::Cash, 10_000_000)],
            uuid: (string) Str::uuid(),
            billType: $billType,
            opdMedicinesAmount: $medicinesAmount,
        ));
    }

    /** Yesterday 23:30 a dressing up; today a lab test down, a fee at the OPD, lines at their list price and a voided bill. */
    private function aDay(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-17 23:30', 'Asia/Colombo'));
        $this->ring([new CartLine($this->dressing->getKey(), 2, unitPriceOverride: 70_000)]);

        $this->travelTo(CarbonImmutable::parse('2026-10-18 08:15', 'Asia/Colombo'));
        $this->ring([
            new CartLine($this->cbs->getKey(), 1, unitPriceOverride: 80_000),
            // Typed at its list price: not a change.
            new CartLine($this->dressing->getKey(), 1, unitPriceOverride: 50_000),
            new CartLine($this->dressing->getKey(), 1),
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-10-18 09:00', 'Asia/Colombo'));
        $this->ring([new CartLine($this->fee->getKey(), 1, unitPriceOverride: 200_000)], $this->doctor, $this->doc, BillType::Opd, 0);

        $this->travelTo(CarbonImmutable::parse('2026-10-18 09:30', 'Asia/Colombo'));
        $voided = $this->ring([new CartLine($this->cbs->getKey(), 1, unitPriceOverride: 250_000)]);
        app(CheckoutService::class)->void($voided, $this->owner, 'Rang up twice');

        $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Colombo'));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function rows(?ReportPeriod $period = null, array $filters = []): array
    {
        return iterator_to_array(app(ReportService::class)->priceChangeRows($period ?? ReportPeriod::today($this->shop), $filters), false);
    }

    public function test_it_lists_each_line_charged_other_than_its_list_price_newest_first(): void
    {
        $this->aDay();

        $rows = $this->rows();

        $this->assertSame(['Consultation', 'CBS'], array_column($rows, 'item'));
        $this->assertSame([
            'when' => '2026-10-18 09:00',
            'till' => 'T2',
            'location' => "Doctor's Medicines",
            'quantity' => 1.0,
            'list_price' => 150_000,
            'charged_price' => 200_000,
            'difference' => 50_000,
            'line_difference' => 50_000,
            'cashier' => $this->doctor->name,
        ], array_intersect_key($rows[0], array_flip(['when', 'till', 'location', 'quantity', 'list_price', 'charged_price', 'difference', 'line_difference', 'cashier'])));
        $this->assertSame([100_000, 80_000, -20_000, -20_000, 'T1', 'General Pharmacy', $this->cashier->name], [
            $rows[1]['list_price'], $rows[1]['charged_price'], $rows[1]['difference'], $rows[1]['line_difference'], $rows[1]['till'], $rows[1]['location'], $rows[1]['cashier'],
        ]);
        $this->assertMatchesRegularExpression('/^T1-\d{6}$/', $rows[1]['invoice']);

        $this->assertSame(['count' => 2, 'difference' => 30_000], app(ReportService::class)->priceChangeTotals(ReportPeriod::today($this->shop), []));
    }

    public function test_it_filters_by_the_shops_days_item_cashier_and_counter(): void
    {
        $this->aDay();

        // Yesterday 23:30 in Colombo is yesterday, whatever UTC says; two dressings 200.00 above the list.
        $yesterday = $this->rows(ReportPeriod::yesterday($this->shop));
        $this->assertSame([['2026-10-17 23:30', 'Wound dressing', 2.0, 20_000, 40_000]], array_map(fn (array $row): array => [$row['when'], $row['item'], $row['quantity'], $row['difference'], $row['line_difference']], $yesterday));

        $this->assertSame(['CBS'], array_column($this->rows(filters: ['variant_id' => $this->cbs->getKey()]), 'item'));
        $this->assertSame(['Consultation'], array_column($this->rows(filters: ['cashier_id' => $this->doctor->getKey()]), 'item'));
        $this->assertSame(['CBS'], array_column($this->rows(filters: ['location_id' => $this->general->getKey()]), 'item'));
        $this->assertSame([], $this->rows(filters: ['cashier_id' => $this->owner->getKey()]));
        $this->assertSame(2, app(ReportService::class)->priceChanges(ReportPeriod::today($this->shop), [], 1, 1)->total());
    }

    public function test_the_page_shows_the_rows_and_total_and_every_export_works(): void
    {
        $this->aDay();
        $this->actingAs($this->owner);

        Livewire::withQueryParams(['range' => 'today'])->test(PriceChangesReport::class)
            ->assertSuccessful()
            ->assertSee('Price changes at till')
            ->assertSee('Consultation')
            ->assertSee('CBS')
            ->assertDontSee('Wound dressing')
            ->assertSee('Total (2 lines)')
            ->assertSee('300.00')
            ->set('cashier', (string) $this->doctor->getKey())
            ->assertSee('Total (1 line)')
            ->assertDontSee('CBS');

        $component = Livewire::withQueryParams(['range' => 'today', 'item' => (string) $this->cbs->getKey()])->test(PriceChangesReport::class);
        $component->callTableAction('exportXlsx')->assertFileDownloaded();
        $component->callTableAction('exportPdf')->assertFileDownloaded();

        $csv = $component->instance()->exportCsv();
        ob_start();
        $csv->sendContent();
        $lines = array_map('str_getcsv', array_filter(explode("\n", (string) ob_get_clean())));
        $this->assertSame(['Date', 'Bill', 'Till', 'Counter', 'Item', 'Quantity', 'List price', 'Charged price', 'Difference', 'Line difference', 'Cashier'], $lines[0]);
        $this->assertSame(['CBS', '1000.00', '800.00', '-200.00', '-200.00'], [$lines[1][4], $lines[1][6], $lines[1][7], $lines[1][8], $lines[1][9]]);
        $this->assertSame(['Total', '-200.00'], [$lines[2][0], $lines[2][9]]);

        $this->get(route('reports.print', ['report' => PriceChangesReport::getSlug(), 'range' => 'yesterday']))
            ->assertSuccessful()
            ->assertSee('Wound dressing')
            ->assertDontSee('Consultation');
    }

    public function test_reports_view_opens_it_in_a_pharmacy_shop_only(): void
    {
        $this->assertContains(PriceChangesReport::class, ReportRegistry::PAGES);

        $this->actingAs($this->cashier);
        $this->assertFalse(PriceChangesReport::canAccess());
        $this->cashier->givePermissionTo(['admin.access', 'reports.view']);
        $this->assertTrue(PriceChangesReport::canAccess());

        $this->actingAs($this->owner);
        $this->get(PriceChangesReport::getUrl())->assertSuccessful();
        Feature::for($this->shop)->deactivate('module:pharmacy');
        $this->assertFalse(PriceChangesReport::canAccess());
    }
}
