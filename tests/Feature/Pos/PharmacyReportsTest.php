<?php

namespace Tests\Feature\Pos;

use App\Enums\AdjustmentReason;
use App\Enums\BillType;
use App\Enums\DrugSchedule;
use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Enums\StockMovementType;
use App\Enums\SupplierPaymentMethod;
use App\Models\Device;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Shift;
use App\Models\StockBatch;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\Data\ReceiptLine;
use App\Services\Data\TransferLine;
use App\Services\Data\WriteOffLine;
use App\Services\ExpiryService;
use App\Services\OfflineSaleSyncService;
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Services\ShiftService;
use App\Services\StockService;
use App\Services\StockTransferService;
use App\Services\StockWriteOffService;
use App\Services\SupplierPayablesService;
use App\Services\SupplierService;
use App\Support\ReportPeriod;
use App\Support\UnitCost;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithPurchasing;

/**
 * The reports branch on the demo clinic (docs/PHARMACY.md §9, branch 11),
 * with the clock at 2026-10-18 10:00 in Colombo (BatchTestCase): a day at
 * both counters (General Pharmacy bills in cash and card, a void, an OPD
 * bill at Doctor's Medicines for a consultation plus one Medicines amount
 * by bank transfer, a return put back on the shelf and one that is not)
 * and every report reconciling with the others, with the sale lines and,
 * for stock, with the ledger.
 */
class PharmacyReportsTest extends BatchTestCase
{
    use WorksWithCheckout;
    use WorksWithPurchasing;

    private ReportService $reports;

    private ProductVariant $panadol;

    private ProductVariant $syrupMl;

    private ProductVariant $consult;

    private Device $doc;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reports = app(ReportService::class);
        $this->panadol = $this->medicine(); // tablets at 20.00, Box of 10 at 180.00, cost 10.00
        $this->syrupMl = $this->syrup();     // 5.00 a ml, Bottle of 60 ml at 250.00
        $this->consult = $this->consultation(150_000);
        $this->doc = $this->docTill();
        $this->doctor = $this->cashierAt($this->doctors);

        $this->receive($this->panadol, 'P1', '03/2027', 100);
        $this->receive($this->panadol, 'D1', '03/2027', 50, $this->doctors);
        $this->receive($this->syrupMl, 'S1', '03/2027', 600, $this->doctors, 300);
    }

    private function day(string $from, ?string $to = null): ReportPeriod
    {
        return ReportPeriod::between($this->shop->fresh(), $from, $to ?? $from);
    }

    private function today(): ReportPeriod
    {
        return $this->day('2026-10-18');
    }

    /**
     * @param  list<CartLine>  $lines
     * @param  list<PaymentInput>  $payments
     */
    private function genSale(array $lines, array $payments): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(device: $this->till, cashier: $this->cashier, lines: $lines, payments: $payments));
    }

    /** @param  list<CartLine>  $lines */
    private function opdBill(array $lines, int $medicines, int $paid): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->doc,
            cashier: $this->doctor,
            lines: $lines,
            payments: [new PaymentInput(PaymentMethod::BankTransfer, $paid, 'BOC-123')],
            billType: BillType::Opd,
            opdMedicinesAmount: $medicines,
        ));
    }

    /**
     * The clinic's day: shifts at both counters; at General Pharmacy a box
     * and five tablets in cash (300.00 given, 20.00 change), three tablets by
     * card and a bill of two tablets voided; at Doctor's Medicines an OPD
     * bill of 1,500.00 consultation + 300.00 Medicines (10 ml syrup, 6
     * tablets) by bank transfer; then five tablets back on the shelf (cash
     * refund) and one faulty tablet not restocked (card refund).
     *
     * @return array{cash: Sale, card: Sale, opd: Sale, genShift: Shift, docShift: Shift}
     */
    private function aDayAtTheClinic(): array
    {
        $shifts = app(ShiftService::class);
        $genShift = $shifts->open($this->till, $this->cashier, 500_000);
        $docShift = $shifts->open($this->doc, $this->doctor, 0);

        $cash = $this->genSale([
            new CartLine($this->panadol->getKey(), 1, unitId: $this->boxOf($this->panadol)),
            new CartLine($this->panadol->getKey(), 5),
        ], [new PaymentInput(PaymentMethod::Cash, 30_000)]);
        $card = $this->genSale([new CartLine($this->panadol->getKey(), 3)], [new PaymentInput(PaymentMethod::Card, 6_000)]);
        $voided = $this->genSale([new CartLine($this->panadol->getKey(), 2)], [new PaymentInput(PaymentMethod::Cash, 4_000)]);
        app(CheckoutService::class)->void($voided, $this->owner, 'Rung up twice');

        $opd = $this->opdBill([
            new CartLine($this->consult->getKey(), 1),
            new CartLine($this->syrupMl->getKey(), 10),
            new CartLine($this->panadol->getKey(), 6),
        ], 30_000, 180_000);

        $returns = app(ReturnService::class);
        $returns->process($cash, [$cash->items[1]->getKey() => 5], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);
        $returns->process($card, [$card->items[0]->getKey() => 1], ReturnReason::FaultyDamaged, PaymentMethod::Card, $this->owner, restock: false);

        $shifts->close($genShift, $this->cashier, 513_000);

        return ['cash' => $cash->fresh(), 'card' => $card->fresh(), 'opd' => $opd->fresh(), 'genShift' => $genShift->fresh(), 'docShift' => $docShift->fresh()];
    }

    public function test_the_daily_summary_reconciles_per_counter(): void
    {
        $this->aDayAtTheClinic();

        $counters = collect($this->reports->counters($this->today()))->keyBy('location');
        $gen = $counters['General Pharmacy'];
        $doc = $counters["Doctor's Medicines"];

        // General Pharmacy: 280.00 + 60.00 sold, 100.00 + 20.00 refunded; the void is shown, not counted.
        $this->assertSame([2, 34_000, 12_000, 22_000], [$gen['bills'], $gen['gross'], $gen['refunds'], $gen['net']]);
        $this->assertSame([1, 4_000], [$gen['voids'], $gen['voids_total']]);
        $this->assertSame([0, 22_000], [$gen['services'], $gen['goods']]);

        // Doctor's Medicines: the OPD bill, 1,500.00 consultation and 300.00 Medicines.
        $this->assertSame([1, 180_000, 0, 180_000, 1], [$doc['bills'], $doc['gross'], $doc['refunds'], $doc['net'], $doc['opd_bills']]);
        $this->assertSame([150_000, 30_000, 1.0], [$doc['services'], $doc['goods'], $doc['consultations']]);

        // Quarantine does not sell: no counter.
        $this->assertFalse($counters->has('Quarantine / Expired'));

        // Everything adds up: per counter, by payment method, by cashier, and against the bills.
        $summary = $this->reports->summary($this->today());
        $this->assertSame($summary['net'], $counters->sum('net'));
        $this->assertSame($summary['gross'] - $summary['refunds'], $counters->sum('net'));
        $this->assertSame($counters->sum('net'), $counters->sum('services') + $counters->sum('goods'));
        $this->assertSame($summary['net'], collect($this->reports->paymentMethods($this->today()))->sum('net'));
        $this->assertSame($summary['net'], collect($this->reports->salesByCashier($this->today()))->sum('net'));
        $this->assertSame(
            (int) Sale::query()->where('status', '!=', 'voided')->sum('grand_total') - (int) SaleReturn::query()->sum('refund_total'),
            $summary['net'],
        );

        // One counter only.
        $methods = collect($this->reports->paymentMethods($this->today(), $this->general->getKey()))->keyBy('key');
        $this->assertSame(28_000 - 10_000, $methods['cash']['net']); // 300.00 less 20.00 change, less the cash refund
        $this->assertSame(6_000 - 2_000, $methods['card']['net']);
        $this->assertFalse($methods->has('bank_transfer'));
        $this->assertSame([180_000], collect($this->reports->paymentMethods($this->today(), $this->doctors->getKey()))->pluck('net')->all());
        $this->assertCount(1, $this->reports->counters($this->today(), $this->doctors->getKey()));
    }

    public function test_the_daily_summary_shows_each_shifts_cash_expected_and_counted(): void
    {
        $day = $this->aDayAtTheClinic();

        $shifts = collect($this->reports->shiftCash($this->today()))->keyBy('till');

        // Float 5,000.00 + cash 280.00 (the voided bill's cash is not in the drawer) - refund 100.00.
        $this->assertSame([500_000, 28_000, 10_000, 518_000, 513_000, -5_000], [
            $shifts['T1']['opening_cash'], $shifts['T1']['cash_sales'], $shifts['T1']['cash_refunds'],
            $shifts['T1']['expected_cash'], $shifts['T1']['counted_cash'], $shifts['T1']['difference'],
        ]);
        $this->assertSame($day['genShift']->expected_cash, $shifts['T1']['expected_cash']);

        // The OPD's shift is still open: what its drawer should hold now (bank transfers are not cash).
        $this->assertSame(['Open', 0, null], [$shifts['T2']['closed_at'], $shifts['T2']['expected_cash'], $shifts['T2']['counted_cash']]);
        $this->assertCount(1, $this->reports->shiftCash($this->today(), $this->doctors->getKey()));
    }

    public function test_opd_lump_sums_are_reported_by_their_stored_allocations(): void
    {
        $opd = $this->aDayAtTheClinic()['opd'];
        $shares = $opd->items->where('is_opd_medicine', true)->mapWithKeys(fn ($item): array => [$item->product_variant_id => $item->line_total]);
        $this->assertSame(30_000, $shares->sum());

        // Consultations: one, 1,500.00; the Medicines amount beside it.
        $this->assertSame(
            ['opd_bills' => 1, 'consultations' => 1.0, 'income' => 150_000, 'refunds' => 0, 'net' => 150_000, 'opd_medicines' => 30_000],
            $this->reports->consultations($this->today()),
        );

        // Sales by item at Doctor's Medicines: each medicine at its stored share.
        $items = collect($this->reports->salesByItem($this->today(), $this->doctors->getKey()))->keyBy('sku');
        $this->assertSame($shares[$this->syrupMl->getKey()], $items[$this->syrupMl->sku]['net']);
        $this->assertSame($shares[$this->panadol->getKey()], $items[$this->panadol->sku]['net']);
        $this->assertSame(150_000, $items[$this->consult->sku]['net']);
        $this->assertSame(10.0, $items[$this->syrupMl->sku]['quantity']);

        // Profit at Doctor's Medicines: the medicines' shares as revenue, the consultation apart with no cost of goods.
        $profit = $this->reports->profit($this->today(), 'product', $this->doctors->getKey());
        $this->assertSame(30_000, $profit['goods']['revenue']);
        $this->assertSame(10 * 300 + 6 * 1_000, $profit['goods']['cost']);
        $this->assertSame([150_000], array_column($profit['services'], 'revenue'));
        $this->assertSame([0], array_column($profit['services'], 'cost'));
        $this->assertSame(30_000 + 150_000 - 9_000, $profit['total']['profit']);

        $byLocation = collect($this->reports->profit($this->today(), 'location')['rows'])->keyBy('name');
        $this->assertSame(30_000, $byLocation["Doctor's Medicines"]['revenue']);
    }

    public function test_profit_is_revenue_less_each_lines_stored_cost(): void
    {
        $this->aDayAtTheClinic();

        $profit = $this->reports->profit($this->today());

        // Each goods line's own cost (as stored when sold), less what returns put back on the shelf took back.
        $lines = SaleItem::query()->whereHas('sale', fn ($sale) => $sale->where('status', '!=', 'voided'))
            ->whereHas('variant.product', fn ($product) => $product->where('track_stock', true))
            ->get();
        $sold = $lines->sum(fn (SaleItem $item): float => $item->unit_cost * (float) $item->quantity);
        $back = SaleReturnItem::query()->where('restocked', true)->with('saleItem')->get()
            ->sum(fn (SaleReturnItem $line): float => $line->saleItem->unit_cost * (float) $line->base_quantity / (float) $line->saleItem->unit_factor);

        $this->assertSame((int) round($sold - $back), $profit['goods']['cost']);
        $this->assertSame((15 + 3 + 6 - 5) * 1_000 + 10 * 300, $profit['goods']['cost']);
        // The faulty tablet stays a cost: its refund takes back revenue only.
        $this->assertSame(28_000 - 10_000 + 6_000 - 2_000 + 30_000, $profit['goods']['revenue']);
        $this->assertSame($profit['goods']['revenue'] - $profit['goods']['cost'], $profit['goods']['profit']);

        // Every grouping adds up to the same, and the old per-product report agrees.
        foreach (['category', 'location'] as $by) {
            $rows = collect($this->reports->profit($this->today(), $by)['rows']);
            $this->assertSame($profit['goods']['cost'], $rows->sum('cost'), $by);
            $this->assertSame($profit['goods']['revenue'], $rows->sum('revenue'), $by);
        }

        $this->assertSame($profit['total']['profit'], collect($this->reports->profitByProduct($this->today()))->sum('profit'));
        $this->assertSame($profit['total']['profit'], $this->reports->grossProfit($this->today()));
        $this->assertSame(ReportService::margin($profit['goods']['revenue'], $profit['goods']['profit']), $profit['goods']['margin']);
    }

    /**
     * A box of 30 at an average of 33.3333 a tablet costs 1,000 (10.00): the
     * line's own cost. The ledger keeps 33 a tablet (9.90 a box), which is
     * why profit reads the line. Ten tablets back take back a third of it.
     */
    public function test_a_box_costs_what_its_line_stored_with_no_rounding_per_tablet(): void
    {
        $cheap = $this->medicine('Cheap 5 mg Tablets', [['name' => 'Box of 30', 'factor' => 30, 'price' => 3_000]]);
        $this->receive($cheap, 'C1', '03/2027', 90, cost: '33.3333');
        $box = (int) $cheap->units()->where('name', 'Box of 30')->value('id');

        $sale = $this->genSale([new CartLine($cheap->getKey(), 1, unitId: $box)], [new PaymentInput(PaymentMethod::Cash, 3_000)]);
        $this->assertSame(1_000, $sale->items[0]->unit_cost);
        $this->assertSame(-30 * 33, (int) StockMovement::query()->where('reference_id', $sale->getKey())->where('type', StockMovementType::Sale->value)->get()->sum(fn (StockMovement $movement): float => (float) $movement->quantity * $movement->unit_cost));
        $this->assertSame([3_000, 1_000, 2_000], array_values(array_intersect_key($this->reports->profit($this->today())['goods'], array_flip(['revenue', 'cost', 'profit']))));

        app(ReturnService::class)->process($sale, [$sale->items[0]->getKey() => 10], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner, inBaseUnits: true);

        $profit = $this->reports->profit($this->today());
        $this->assertSame([2_000, 667, 1_333], [$profit['goods']['revenue'], $profit['goods']['cost'], $profit['goods']['profit']]);
    }

    public function test_a_return_counts_at_its_original_cost_even_after_the_average_moved(): void
    {
        $sale = $this->genSale([new CartLine($this->panadol->getKey(), 10)], [new PaymentInput(PaymentMethod::Cash, 20_000)]);

        // The average at General Pharmacy goes from 10.00 to 14.00 the next day.
        $this->travelTo(CarbonImmutable::parse('2026-10-19 04:30:00', 'UTC'));
        $this->receive($this->panadol, 'P2', '06/2027', 90, cost: 1_800);
        app(ReturnService::class)->process($sale, [$sale->items[0]->getKey() => 4], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);

        $returnDay = $this->reports->profit($this->day('2026-10-19'));
        $this->assertSame(-8_000, $returnDay['goods']['revenue']);
        $this->assertSame(-4 * 1_000, $returnDay['goods']['cost']);

        $both = $this->reports->profit($this->day('2026-10-18', '2026-10-19'));
        $this->assertSame([12_000, 6_000, 6_000], [$both['goods']['revenue'], $both['goods']['cost'], $both['goods']['profit']]);
    }

    public function test_a_sale_made_offline_at_2355_and_uploaded_the_next_day_counts_on_the_day_it_was_made(): void
    {
        $sync = app(OfflineSaleSyncService::class);
        $sync->reserveInvoiceBlock($this->till);
        $uuid = (string) Str::uuid();

        // Sold at 23:55 on the 17th; the till came back online at 10:00 on the 18th, its clock right.
        $result = $sync->sync($this->till, [[
            'uuid' => $uuid,
            'invoice_no' => 'T1-000001',
            'sold_at' => CarbonImmutable::parse('2026-10-17 23:55', 'Asia/Colombo')->toIso8601String(),
            'cashier_id' => $this->cashier->getKey(),
            'lines' => [['variant_id' => $this->panadol->getKey(), 'quantity' => 3, 'unit_price' => 2_000]],
            'payments' => [['method' => 'cash', 'amount' => 6_000]],
            'grand_total' => 6_000,
        ]], now()->toIso8601String());

        $sale = Sale::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertNotNull($sale->synced_at, json_encode($result));
        $this->assertSame('2026-10-18', $sale->synced_at->timezone('Asia/Colombo')->format('Y-m-d'));

        $seventeenth = collect($this->reports->counters($this->day('2026-10-17')))->keyBy('location')['General Pharmacy'];
        $this->assertSame([1, 6_000], [$seventeenth['bills'], $seventeenth['net']]);
        $this->assertSame(0, collect($this->reports->counters($this->today()))->keyBy('location')['General Pharmacy']['bills']);
        $this->assertSame(6_000, $this->reports->profit($this->day('2026-10-17'))['goods']['revenue']);
        $this->assertSame(3 * 1_000, $this->reports->profit($this->day('2026-10-17'))['goods']['cost']);
        $this->assertSame(0, $this->reports->profit($this->today())['goods']['cost']);
        $this->assertSame(6_000, collect($this->reports->salesByDay($this->day('2026-10-17', '2026-10-18')))->keyBy('date')['2026-10-17']['net']);
    }

    public function test_monthly_sales_by_location_schedule_category_and_item_add_up(): void
    {
        $this->aDayAtTheClinic();
        $month = ReportPeriod::thisMonth($this->shop->fresh());
        $net = $this->reports->summary($month)['net'];

        foreach (['location', 'schedule', 'category', 'brand'] as $by) {
            $this->assertSame($net, collect($this->reports->salesByGroup($month, $by))->sum('net'), $by);
        }

        $this->assertSame($net, collect($this->reports->salesByItem($month))->sum('net'));
        $this->assertSame($net, collect($this->reports->salesByDay($month))->sum('net'));

        $schedules = collect($this->reports->salesByGroup($month, 'schedule'))->keyBy('name');
        $this->assertSame(150_000, $schedules['Not a medicine']['net']);
        $this->assertSame($net - 150_000, $schedules['Over the counter']['net']);

        // In base units and in packs: 10 + 5 - 5 + 3 - 1 + 6 tablets.
        $panadol = collect($this->reports->salesByItem($month))->firstWhere('sku', $this->panadol->sku);
        $this->assertSame(18.0, $panadol['quantity']);
        $this->assertSame('1 × Box of 10 + 8 Tablet', $panadol['packs']);
        $this->assertSame('OTC', $panadol['schedule']);
    }

    public function test_stock_valuation_matches_the_ledger_by_location_and_by_batch(): void
    {
        $this->aDayAtTheClinic();
        app(StockTransferService::class)->create($this->general, $this->doctors, [new TransferLine($this->panadol->getKey(), 20)], $this->owner);

        $byLocation = collect($this->reports->stockValuation(true, by: 'location'))->keyBy('location');

        foreach ([$this->general, $this->doctors] as $location) {
            $fromLedger = 0.0;

            foreach (StockLevel::query()->where('location_id', $location->getKey())->get() as $level) {
                $onHand = (float) StockMovement::query()->where('location_id', $location->getKey())->where('product_variant_id', $level->product_variant_id)->sum('quantity');
                $this->assertSame($onHand, (float) $level->quantity);
                $fromLedger += max($onHand, 0) * (float) $level->average_cost;
            }

            $this->assertSame((int) round($fromLedger), $byLocation[$location->name]['cost_value'], $location->name);
            $this->assertSame((int) round($fromLedger), $this->reports->stockValueAtCost($location->getKey()));
        }

        $this->assertSame($this->reports->stockValueAtCost(), collect($this->reports->stockValuation(true, by: 'item'))->sum('cost_value'));
        $this->assertSame($this->reports->stockValueAtCost(), collect($this->reports->stockValuation(true))->sum('cost_value'));

        // By batch: each batch's own movements at its own cost.
        $batches = $this->reports->batchValuation();
        $expected = StockBatch::query()->where('is_unallocated', false)->get()
            ->filter(fn (StockBatch $batch): bool => (float) StockMovement::query()->where('stock_batch_id', $batch->getKey())->sum('quantity') > 0)
            ->sum(fn (StockBatch $batch): int => UnitCost::times($batch->unit_cost, (float) StockMovement::query()->where('stock_batch_id', $batch->getKey())->sum('quantity')));
        $this->assertSame($expected, array_sum(array_column($batches, 'value')));
        $this->assertSame(['General Pharmacy', "Doctor's Medicines"], array_values(array_unique(array_column($batches, 'location'))));
        $this->assertLedgerMatches();
    }

    public function test_the_expiry_report_lists_batches_within_n_days_or_expired_at_batch_cost(): void
    {
        $this->receive($this->panadol, 'SOON', '11/2026', 20, cost: 900);   // 30/11/2026: 43 days left
        $this->receive($this->panadol, 'LATE', '02/2027', 10, cost: 950);   // 28/02/2027: 133 days left
        $this->foundExpired($this->panadol, 'OLD', '09/2026', 5, $this->doctors);

        $soon = $this->reports->expiry(ExpiryService::SOON, 60);
        $this->assertSame(['SOON'], array_column($soon, 'batch'));
        $this->assertSame([43, 18_000], [$soon[0]['days_left'], $soon[0]['value']]);

        $this->assertSame(['SOON', 'LATE'], array_column($this->reports->expiry(ExpiryService::SOON, 140), 'batch'));
        // The shop's window (90 days) when none is given.
        $this->assertSame(['SOON'], array_column($this->reports->expiry(ExpiryService::SOON), 'batch'));

        $expired = $this->reports->expiry(ExpiryService::EXPIRED);
        $this->assertSame(['OLD'], array_column($expired, 'batch'));
        $this->assertSame("Doctor's Medicines", $expired[0]['location']);
        $this->assertSame([], $this->reports->expiry(ExpiryService::EXPIRED, null, $this->general->getKey()));
    }

    public function test_slow_moving_and_dead_stock_by_days_since_the_last_sale(): void
    {
        $slow = $this->medicine('Slow Mover 5 mg Tablets', packs: []);
        $this->receive($slow, 'SM1', '12/2027', 40, cost: 500);
        $this->genSale([new CartLine($this->panadol->getKey(), 2)], [new PaymentInput(PaymentMethod::Cash, 4_000)]);

        // A hundred days on: Panadol sold today; Slow Mover never; the syrup at the OPD never.
        $this->travelTo(CarbonImmutable::parse('2027-01-26 04:30:00', 'UTC'));
        $this->genSale([new CartLine($this->panadol->getKey(), 1)], [new PaymentInput(PaymentMethod::Cash, 2_000)]);

        $dead = collect($this->reports->slowMoving(90))->keyBy(fn (array $row): string => $row['sku'].'@'.$row['location']);
        $this->assertTrue($dead->has($slow->sku.'@General Pharmacy'));
        $this->assertTrue($dead->has($this->syrupMl->sku."@Doctor's Medicines"));
        $this->assertFalse($dead->has($this->panadol->sku.'@General Pharmacy'));
        // Panadol at the OPD never sold there: dead there.
        $this->assertTrue($dead->has($this->panadol->sku."@Doctor's Medicines"));
        $this->assertSame([40.0, null, 20_000], [$dead[$slow->sku.'@General Pharmacy']['on_hand'], $dead[$slow->sku.'@General Pharmacy']['last_sold'], $dead[$slow->sku.'@General Pharmacy']['value']]);

        $all = collect($this->reports->slowMoving(90, $this->general->getKey(), deadOnly: false))->keyBy('sku');
        $this->assertSame([1.0, '2027-01-26', 0, false], [$all[$this->panadol->sku]['sold'], $all[$this->panadol->sku]['last_sold'], $all[$this->panadol->sku]['days_since'], $all[$this->panadol->sku]['dead']]);

        // Stock that arrived within the window is not dead yet.
        $fresh = $this->medicine('Fresh 1 mg Tablets', packs: []);
        $this->receive($fresh, 'F1', '12/2027', 10);
        $this->assertFalse(collect($this->reports->slowMoving(90))->contains('sku', $fresh->sku));
    }

    public function test_adjustments_write_offs_and_transfers_by_period(): void
    {
        $soon = $this->receive($this->panadol, 'SOON', '11/2026', 20, cost: 900);
        app(StockWriteOffService::class)->writeOff([new WriteOffLine($soon->getKey())], AdjustmentReason::Expired, $this->owner);
        app(StockService::class)->adjustBatchBy($this->syrupMl, $this->doctors, $this->batch($this->syrupMl, 'S1', $this->doctors), -5, AdjustmentReason::Damaged, $this->owner);
        app(StockTransferService::class)->create($this->general, $this->doctors, [new TransferLine($this->panadol->getKey(), 2, $this->boxOf($this->panadol))], $this->owner);
        app(StockTransferService::class)->create($this->general, $this->doctors, [new TransferLine($this->panadol->getKey(), 5)], $this->owner);

        $adjustments = collect($this->reports->stockAdjustments($this->today()))->keyBy(fn (array $row): string => $row['source'].':'.$row['reason']);
        $this->assertSame([20.0, -18_000], [$adjustments['write_off:expired']['quantity_out'], $adjustments['write_off:expired']['value']]);
        $this->assertSame([5.0, -1_500], [$adjustments['manual:damaged']['quantity_out'], $adjustments['manual:damaged']['value']]);
        $this->assertCount(1, $this->reports->stockAdjustments($this->today(), $this->doctors->getKey()));

        // At General Pharmacy's average: (100 x 10.00 + 20 x 9.00) / 120; the write-off at batch cost left it as it was.
        $average = (string) StockLevel::query()->where('location_id', $this->general->getKey())->where('product_variant_id', $this->panadol->getKey())->value('average_cost');
        $this->assertSame('983.3333', UnitCost::normalize($average));

        $transfers = $this->reports->transfers($this->today(), $this->general->getKey(), $this->doctors->getKey());
        $this->assertCount(1, $transfers);
        $this->assertSame(["General Pharmacy → Doctor's Medicines", 2, 25.0, '2 × Box of 10 + 5 Tablet', UnitCost::times($average, 25)], [
            $transfers[0]['route'], $transfers[0]['transfers'], $transfers[0]['quantity'], $transfers[0]['packs'], $transfers[0]['value'],
        ]);
        $this->assertSame([], $this->reports->transfers($this->today(), $this->doctors->getKey()));
        $this->assertSame([], $this->reports->transfers($this->day('2026-10-17')));
    }

    public function test_purchases_by_supplier_and_month_with_discounts_tax_and_free_goods(): void
    {
        $hemas = $this->supplier();
        $spc = $this->supplier('SPC');

        // 10 boxes at 90.00 with 2 free, 45.00 off the invoice and 153.90 VAT.
        $this->postReceipt($hemas, [new ReceiptLine($this->panadol->getKey(), 10, $this->boxOf($this->panadol), freeQuantity: 2, unitCost: 9_000, batchNo: 'H1', expiry: '06/2027')], invoiceDiscount: 4_500, invoiceNo: 'HPL-1', invoiceTax: 15_390);
        $spcGrn = $this->postReceipt($spc, [new ReceiptLine($this->syrupMl->getKey(), 600, unitCost: 300, batchNo: 'S9', expiry: '06/2027')], invoiceNo: 'SPC-1');
        $september = $this->postReceipt($spc, [new ReceiptLine($this->syrupMl->getKey(), 60, unitCost: 300, batchNo: 'S8', expiry: '06/2027')], invoiceNo: 'SPC-0', receivedOn: '2026-09-30');

        $period = $this->day('2026-09-01', '2026-10-18');
        $bySupplier = collect($this->reports->purchases($period))->keyBy('name');
        $this->assertSame(
            ['name' => 'Hemas Pharmaceuticals', 'grns' => 1, 'reversals' => 0, 'subtotal' => 90_000, 'discounts' => 4_500, 'tax' => 15_390, 'total' => 100_890, 'free_goods' => 18_000],
            $bySupplier['Hemas Pharmaceuticals'],
        );
        $this->assertSame([2, 198_000, 0], [$bySupplier['SPC']['grns'], $bySupplier['SPC']['total'], $bySupplier['SPC']['free_goods']]);

        $byMonth = collect($this->reports->purchases($period, 'month'))->keyBy('name');
        $this->assertSame(['2026-09', '2026-10'], $byMonth->keys()->all());
        $this->assertSame([18_000, 100_890 + 180_000], [$byMonth['2026-09']['total'], $byMonth['2026-10']['total']]);

        $this->assertSame(
            collect($this->reports->purchases($period))->sum('total'),
            collect($this->reports->goodsReceived($period))->sum('total'),
        );
        $this->assertSame(['SPC-0'], array_column($this->reports->goodsReceived($this->day('2026-09-30')), 'invoice'));
        $this->assertSame([$september->grn_number, $spcGrn->grn_number], array_column($this->reports->goodsReceived($period, supplierId: $spc->getKey()), 'grn'));

        // Every supplier's aging adds up to its balance.
        app(SupplierService::class)->recordPayment($spc, 50_000, SupplierPaymentMethod::BankTransfer, $this->owner);
        foreach (app(SupplierPayablesService::class)->agingBySupplier() as $row) {
            $supplier = $row['supplier'] === 'SPC' ? $spc : $hemas;
            $this->assertSame(app(SupplierService::class)->balance($supplier)['balance'], $row['balance'], $row['supplier']);
            $this->assertSame($row['total'], $row['current'] + $row['1_30'] + $row['31_60'] + $row['61_90'] + $row['over_90']);
        }
    }

    public function test_the_controlled_drug_register_lists_every_movement_with_the_patient_and_running_balance(): void
    {
        $this->setShopSetting('controlled_register', true);
        $morphine = $this->medicine('Morphine 10 mg Tablets', packs: [], schedule: DrugSchedule::Controlled);
        $this->receive($morphine, 'M1', '12/2027', 30);

        $this->travelTo(CarbonImmutable::parse('2026-10-19 04:30:00', 'UTC'));
        $sale = app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: [new CartLine($morphine->getKey(), 4, prescriber: 'Dr. S. Perera', prescriptionRef: 'RX-7')],
            payments: [new PaymentInput(PaymentMethod::Cash, 8_000)],
            customerName: 'A. Silva',
        ));
        app(ReturnService::class)->process($sale, [$sale->items[0]->getKey() => 1], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);
        app(StockTransferService::class)->create($this->general, $this->doctors, [new TransferLine($morphine->getKey(), 5)], $this->owner);

        $this->assertTrue($this->reports->controlledRegisterEnabled());
        $rows = $this->reports->controlledRegister($this->day('2026-10-19'), $this->general->getKey());

        $this->assertSame(['Balance brought forward', 30.0], [$rows[0]['document'], $rows[0]['balance']]);
        $this->assertSame([null, 4.0, 26.0, 'A. Silva', 'Dr. S. Perera (Rx RX-7)'], [$rows[1]['in'], $rows[1]['out'], $rows[1]['balance'], $rows[1]['patient'], $rows[1]['prescriber']]);
        $this->assertStringContainsString($sale->invoice_no, $rows[1]['document']);
        $this->assertSame([1.0, 27.0, 'A. Silva', 'Dr. S. Perera'], [$rows[2]['in'], $rows[2]['balance'], $rows[2]['patient'], $rows[2]['prescriber']]);
        $this->assertSame([5.0, 22.0, null], [$rows[3]['out'], $rows[3]['balance'], $rows[3]['patient']]);
        $this->assertStringContainsString('M1', (string) $rows[1]['batch']);
        $this->assertCount(4, $rows);

        // Panadol is not controlled: not in the register.
        $this->assertNotContains('Panadol 500 mg Tablets', array_column($this->reports->controlledRegister($this->day('2026-10-18', '2026-10-19')), 'medicine'));
        $doctors = $this->reports->controlledRegister($this->day('2026-10-19'), $this->doctors->getKey());
        $this->assertSame([0.0, 5.0], array_column($doctors, 'balance'));

        $this->setShopSetting('controlled_register', false);
        $this->assertFalse($this->reports->controlledRegisterEnabled());
    }
}
