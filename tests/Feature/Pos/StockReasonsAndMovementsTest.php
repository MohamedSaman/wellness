<?php

namespace Tests\Feature\Pos;

use App\Enums\AdjustmentReason;
use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Enums\StockMovementType;
use App\Exceptions\PosException;
use App\Filament\Pages\Reports\StockMovementsReport;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Services\StockService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/** Fixed reasons for adjustments and returns, damaged returns, and the stock movements report. */
class StockReasonsAndMovementsTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->shop->update(['timezone' => 'Asia/Colombo']);
        $this->travelTo(CarbonImmutable::parse('2026-09-16 04:30:00', 'UTC')); // 10:00 in Colombo
    }

    private function sale(float $quantity = 3): Sale
    {
        return app(CheckoutService::class)->checkout($this->cart([new CartLine($this->variant('CPR8EA-9')->getKey(), $quantity)]));
    }

    public function test_an_adjustment_stores_its_reason_code_and_shows_the_label(): void
    {
        $movement = app(StockService::class)->adjustTo($this->variant('CPR8EA-9'), $this->location, 38, AdjustmentReason::LostStolen, $this->owner);

        $this->assertSame('lost_stolen', $movement->reason_code);
        $this->assertSame('Lost / stolen', $movement->reasonLabel());
        $this->assertNull($movement->note);
    }

    public function test_an_adjustment_for_another_reason_needs_a_note(): void
    {
        $plug = $this->variant('CPR8EA-9');

        foreach ([null, '   '] as $note) {
            try {
                app(StockService::class)->adjustTo($plug, $this->location, 35, AdjustmentReason::Other, $this->owner, $note);
                $this->fail('An "Other" adjustment was recorded without a note.');
            } catch (PosException $exception) {
                $this->assertSame('Add a note explaining the adjustment.', $exception->getMessage());
            }
        }

        $this->assertSame(40.0, $this->stockOf($plug));

        $movement = app(StockService::class)->adjustTo($plug, $this->location, 35, AdjustmentReason::Other, $this->owner, 'Used for the display bike');
        $this->assertSame('other', $movement->reason_code);
        $this->assertSame('Used for the display bike', $movement->note);
    }

    public function test_the_adjust_action_offers_the_fixed_reasons_and_requires_a_note_for_other(): void
    {
        $this->actingAs($this->owner);
        $plug = $this->variant('CPR8EA-9');

        Livewire::test(ListStockLevels::class)
            ->callTableAction('adjust', $plug, data: ['counted_quantity' => 39, 'reason' => 'other', 'note' => ''])
            ->assertHasTableActionErrors(['note' => 'required']);

        Livewire::test(ListStockLevels::class)
            ->callTableAction('adjust', $plug, data: ['counted_quantity' => 39, 'reason' => 'made_up'])
            ->assertHasTableActionErrors(['reason']);

        $this->assertSame(40.0, $this->stockOf($plug));

        Livewire::test(ListStockLevels::class)
            ->callTableAction('adjust', $plug, data: ['counted_quantity' => 39, 'reason' => 'expired'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('expired', StockMovement::query()->latest('id')->value('reason_code'));
    }

    public function test_a_return_stores_its_reason_code_and_note(): void
    {
        $sale = $this->sale();

        $return = app(ReturnService::class)->process($sale, [$sale->items->first()->getKey() => 1], ReturnReason::WrongItem, PaymentMethod::Cash, $this->owner, note: 'Needed the 150cc plug');

        $this->assertSame(ReturnReason::WrongItem, $return->reason_code);
        $this->assertSame('Needed the 150cc plug', $return->note);

        $movement = StockMovement::query()->where('type', StockMovementType::Return)->firstOrFail();
        $this->assertSame('Wrong item', $movement->reasonLabel());
    }

    public function test_a_damaged_return_refunds_the_customer_but_leaves_stock_unchanged(): void
    {
        $sale = $this->sale(3);
        $item = $sale->items->firstOrFail();
        $plug = $this->variant('CPR8EA-9');
        $this->assertSame(37.0, $this->stockOf($plug));
        $movementsBefore = StockMovement::query()->count();

        $this->actingAs($this->owner);

        // Choosing "Faulty / damaged" switches each line's "Put back into stock?" to No.
        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->mountAction('return')
            ->assertActionDataSet($this->restocks([true]))
            ->setActionData(['reason' => 'faulty_damaged'])
            ->assertActionDataSet($this->restocks([false]))
            ->setActionData([
                'items' => [['sale_item_id' => $item->getKey(), 'quantity' => 1, 'restock' => false]],
                'refund_method' => 'cash',
                'note' => 'Cracked insulator',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $return = SaleReturn::query()->with('items')->firstOrFail();

        $this->assertSame(37.0, $this->stockOf($plug));
        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $this->assertSame(145_000, $return->refund_total);
        $this->assertFalse((bool) $return->items->first()->restocked);
        $this->assertSame(ReturnReason::FaultyDamaged, $return->reason_code);
        $this->assertSame(145_000, $sale->fresh()->refunded_total);

        $row = collect(app(ReportService::class)->voidsAndReturns(ReportPeriod::today($this->shop->fresh())))->firstWhere('type', 'Return');
        $this->assertSame('Not restocked', $row['stock']);
        $this->assertSame('Faulty / damaged — Cracked insulator', $row['reason']);
    }

    public function test_other_return_reasons_put_the_items_back_by_default(): void
    {
        $sale = $this->sale(3);
        $this->actingAs($this->owner);

        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->mountAction('return')
            ->setActionData(['reason' => 'faulty_damaged'])
            ->setActionData(['reason' => 'changed_mind'])
            ->assertActionDataSet($this->restocks([true]));
    }

    /**
     * Each return line's "Put back into stock?", in order.
     *
     * @param  list<bool>  $expected
     */
    private function restocks(array $expected): \Closure
    {
        return function (array $state) use ($expected): array {
            $this->assertSame($expected, array_values(array_map(fn (array $row): bool => (bool) $row['restock'], $state['items'])));

            return [];
        };
    }

    public function test_each_movement_shows_and_links_its_source_document(): void
    {
        $sale = $this->sale(2);
        $return = app(ReturnService::class)->process($sale, [$sale->items->first()->getKey() => 1], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);
        app(StockService::class)->adjustTo($this->variant('CPR8EA-9'), $this->location, 30, AdjustmentReason::CountCorrection, $this->owner);

        $rows = collect(app(ReportService::class)->stockMovements(ReportPeriod::today($this->shop->fresh()), false, 1, 50)->items())->keyBy('type');

        $this->assertSame($sale->invoice_no, $rows['Sale']['reference']);
        $this->assertSame($sale->uuid, $rows['Sale']['reference_sale']);
        $this->assertSame($return->return_no, $rows['Return']['reference']);
        $this->assertSame($sale->uuid, $rows['Return']['reference_sale']);
        $this->assertSame('Manual', $rows['Adjustment Out']['reference']);
        $this->assertNull($rows['Adjustment Out']['reference_sale']);

        $this->actingAs($this->owner);
        $saleUrl = SaleResource::getUrl('view', ['record' => $sale]);

        Livewire::test(StockMovementsReport::class)
            ->assertSee($sale->invoice_no)
            ->assertSee($return->return_no)
            ->assertSee('Manual')
            ->assertSeeHtml('href="'.e($saleUrl).'"');
    }

    public function test_filters_narrow_the_rows_and_the_totals_cover_the_whole_filtered_range(): void
    {
        $this->sale(2);
        app(StockService::class)->stockIn($this->variant('MOTUL'), $this->location, 5, $this->owner, unitCost: 2_000);
        $this->actingAs($this->owner);

        $page = Livewire::withQueryParams(['types' => ['sale']])->test(StockMovementsReport::class);
        $this->assertSame(['quantity' => -2.0, 'value' => -2 * $this->variant('CPR8EA-9')->cost_price, 'count' => 1], $page->instance()->totals());
        $page->assertSee('Total (1 movement)')->assertSee('Value at cost');

        $page = Livewire::withQueryParams(['product' => 'motul'])->test(StockMovementsReport::class);
        $this->assertSame(['quantity' => 5.0, 'value' => 10_000, 'count' => 1], $page->instance()->totals());

        $page = Livewire::withQueryParams(['user' => (string) $this->cashier->getKey()])->test(StockMovementsReport::class);
        $this->assertSame(1, $page->instance()->totals()['count']);
    }

    public function test_cost_value_in_the_totals_needs_cost_view(): void
    {
        $this->sale(1);
        $this->cashier->givePermissionTo(['admin.access', 'reports.view']);
        $this->actingAs($this->cashier);

        $page = Livewire::test(StockMovementsReport::class);

        $this->assertNull($page->instance()->totals()['value']);
        $page->assertDontSee('Value at cost')->assertTableColumnDoesNotExist('unit_cost');
    }

    public function test_the_csv_has_the_on_screen_columns_filters_and_totals(): void
    {
        $sale = $this->sale(2);
        app(StockService::class)->adjustTo($this->variant('CPR8EA-9'), $this->location, 37, AdjustmentReason::Damaged, $this->owner, 'Box crushed');
        $this->actingAs($this->owner);
        $sku = $this->variant('CPR8EA-9')->sku;

        // Unit cost, value and reason are hidden by default, so the CSV leaves them out too.
        $csv = "When,SKU,Product,Type,Quantity,Balance,Reference,By\n"
            ."\"2026-09-16 10:00\",{$sku},\"NGK Spark Plug\",Sale,-2,38,{$sale->invoice_no},\"{$this->cashier->name}\"\n"
            ."Total,,,,-2,,,\n";

        Livewire::withQueryParams(['from' => '2026-09-16', 'to' => '2026-09-16', 'types' => ['sale']])
            ->test(StockMovementsReport::class)
            ->callTableAction('export')
            ->assertFileDownloaded('stock-movements-report_2026-09-16_2026-09-16.csv', $csv);

        // Showing every column puts them in the CSV as well.
        $cost = $this->variant('CPR8EA-9')->cost_price;
        $csv = "When,SKU,Product,Type,Quantity,Balance,Reference,\"Unit cost\",\"Value at cost\",\"Reason / note\",By\n"
            ."\"2026-09-16 10:00\",{$sku},\"NGK Spark Plug\",\"Adjustment Out\",-1,37,Manual,".number_format($cost / 100, 2, '.', '').','.number_format(-$cost / 100, 2, '.', '').",\"Damaged — Box crushed\",\"{$this->owner->name}\"\n"
            .'Total,,,,-1,,,,'.number_format(-$cost / 100, 2, '.', '').",,\n";

        Livewire::withQueryParams(['from' => '2026-09-16', 'to' => '2026-09-16', 'types' => ['adjustment_out']])
            ->test(StockMovementsReport::class)
            ->toggleAllTableColumns()
            ->callTableAction('export')
            ->assertFileDownloaded('stock-movements-report_2026-09-16_2026-09-16.csv', $csv);
    }
}
