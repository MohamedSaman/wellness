<?php

namespace Tests\Feature\Pos\Mysql;

use App\Enums\BillType;
use App\Enums\DrugSchedule;
use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Enums\StockMovementType;
use App\Filament\Pages\Reports\ControlledDrugRegisterReport;
use App\Filament\Pages\Reports\DailySummaryReport;
use App\Filament\Pages\Reports\Export\ReportExporter;
use App\Filament\Pages\Reports\ReportPage;
use App\Filament\Pages\Reports\ReportRegistry;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockLevel;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\Data\ReceiptLine;
use App\Services\Data\TransferLine;
use App\Services\ExpiryService;
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Services\StockTransferService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithPurchasing;

/**
 * The reports on MySQL (docs/PHARMACY.md §9, branch 11): the queries run
 * on MySQL's DATE, DECIMAL(20,4) costs and ONLY_FULL_GROUP_BY, and still
 * reconcile; with POS_REPORTS_BENCH=1, a year of demo-scale data (about
 * 100,000 sale lines, POS_REPORTS_BENCH_LINES) and every report timed as
 * its page computes it (a paged report: its first page and totals) against
 * POS_REPORTS_BENCH_MAX_SECONDS (2 by default); each Excel export of the
 * whole range is timed too, and printed.
 */
#[Group('mysql')]
class ReportsMysqlTest extends MysqlTestCase
{
    use WorksWithCheckout;
    use WorksWithPurchasing;

    public function test_the_reports_reconcile_on_mysql_with_four_place_costs(): void
    {
        Filament::setCurrentPanel('admin');
        $this->setShopSetting('controlled_register', true);
        $reports = app(ReportService::class);
        $checkout = app(CheckoutService::class);
        $doc = $this->docTill();
        $doctor = $this->cashierAt($this->doctors);
        $consult = $this->consultation(150_000);
        $tabs = $this->medicine('Cheap 5 mg Tablets', [['name' => 'Box of 30', 'factor' => 30, 'price' => 3_000]]);
        $morphine = $this->medicine('Morphine 10 mg Tablets', [], schedule: DrugSchedule::Controlled);

        // A box of 30 at 10.00: 33.3333 a tablet.
        $this->receive($tabs, 'C1', '03/2027', 300, cost: '33.3333');
        $this->receive($tabs, 'C2', '11/2026', 60, $this->doctors, '41.6667');
        $this->receive($morphine, 'M1', '12/2027', 20);

        $box = (int) $tabs->units()->where('name', 'Box of 30')->value('id');
        $sale = $checkout->checkout(new CheckoutData(device: $this->till, cashier: $this->cashier, lines: [new CartLine($tabs->getKey(), 2, unitId: $box), new CartLine($tabs->getKey(), 7)], payments: [new PaymentInput(PaymentMethod::Cash, 20_000)]));
        $checkout->checkout(new CheckoutData(device: $this->till, cashier: $this->cashier, lines: [new CartLine($morphine->getKey(), 3, prescriber: 'Dr. S. Perera')], payments: [new PaymentInput(PaymentMethod::Card, 6_000)], customerName: 'A. Silva'));
        $checkout->checkout(new CheckoutData(
            device: $doc, cashier: $doctor,
            lines: [new CartLine($consult->getKey(), 1), new CartLine($tabs->getKey(), 9)],
            payments: [new PaymentInput(PaymentMethod::BankTransfer, 151_000, 'REF-1')],
            billType: BillType::Opd, opdMedicinesAmount: 1_000,
        ));
        app(ReturnService::class)->process($sale, [$sale->items[1]->getKey() => 4], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);
        app(StockTransferService::class)->create($this->general, $this->doctors, [new TransferLine($tabs->getKey(), 1, $box)], $this->owner);
        $this->postReceipt($this->supplier(), [new ReceiptLine($tabs->getKey(), 2, $box, freeQuantity: 1, unitCost: 1_000, batchNo: 'H1', expiry: '06/2027')], invoiceTax: 300, invoiceNo: 'HPL-9', receivedOn: '2026-10-01');

        $today = ReportPeriod::today($this->shop->fresh());
        $net = $reports->summary($today)['net'];
        $counters = collect($reports->counters($today));
        // 2 boxes at 30.00 and 7 tablets at 20.00; 3 morphine at 20.00; the OPD bill; 4 tablets back.
        $this->assertSame(6_000 + 14_000 + 6_000 + 151_000 - 4 * 2_000, $net);
        $this->assertSame($net, $counters->sum('net'));
        $this->assertSame($net, $counters->sum('services') + $counters->sum('goods'));
        $this->assertSame($net, collect($reports->paymentMethods($today))->sum('net'));
        $this->assertSame($net, collect($reports->salesByItem($today))->sum('net'));
        $this->assertSame($net, collect($reports->salesByGroup($today, 'schedule'))->sum('net'));
        $this->assertSame(['opd_bills' => 1, 'consultations' => 1.0, 'income' => 150_000, 'refunds' => 0, 'net' => 150_000, 'opd_medicines' => 1_000], $reports->consultations($today));

        // Cost: each line's own, less what was put back at its original line's cost.
        $ledger = (int) round(
            SaleItem::query()->whereHas('variant.product', fn ($product) => $product->where('track_stock', true))->get()->sum(fn (SaleItem $item): float => $item->unit_cost * (float) $item->quantity)
            - SaleReturnItem::query()->where('restocked', true)->with('saleItem')->get()->sum(fn (SaleReturnItem $line): float => $line->saleItem->unit_cost * (float) $line->base_quantity / (float) $line->saleItem->unit_factor),
        );
        $profit = $reports->profit($today);
        $this->assertSame($ledger, $profit['goods']['cost']);
        $this->assertSame($ledger, collect($reports->profit($today, 'location')['rows'])->sum('cost'));
        $this->assertSame($ledger, collect($reports->profit($today, 'category')['rows'])->sum('cost'));

        // Stock values on DECIMAL(20,4): each level's ledger quantity at its average.
        $value = StockLevel::query()->get()->sum(fn (StockLevel $level): float => max((float) $level->quantity, 0) * (float) $level->average_cost);
        $this->assertSame((int) round($value), collect($reports->stockValuation(true, by: 'location'))->sum('cost_value'));
        $this->assertSame((int) round($value), collect($reports->stockValuation(true, by: 'item'))->sum('cost_value'));
        $this->assertNotSame([], $reports->batchValuation());
        $this->assertSame(['C2'], array_column($reports->expiry(ExpiryService::SOON, 60), 'batch'));

        // DATE columns by month, slow-moving subqueries, the register's balances.
        $this->assertSame(['2026-10'], array_column($reports->purchases(ReportPeriod::thisMonth($this->shop->fresh()), 'month'), 'name'));
        $this->assertSame(1_000, $reports->purchases(ReportPeriod::thisMonth($this->shop->fresh()))[0]['free_goods']);
        $this->assertContains($morphine->sku, array_column($reports->slowMoving(30, deadOnly: false), 'sku'));
        $register = $reports->controlledRegister($today, $this->general->getKey());
        $this->assertSame([0.0, 20.0, 17.0], array_column($register, 'balance'));
        $this->assertSame('A. Silva', $register[2]['patient']);

        // Every report page runs on MySQL, and exports.
        $this->actingAs($this->owner);
        foreach (ReportRegistry::PAGES as $class) {
            $page = app($class);
            $page->applyQuery(['range' => 'this_month']);
            $document = $page->document();
            $this->assertNotSame('', $document->title, $class);
        }

        $daily = app(DailySummaryReport::class);
        $daily->applyQuery(['range' => 'today']);
        $this->assertStringStartsWith('%PDF-', app(ReportExporter::class)->pdf($daily->document()));
        $this->assertTrue(ControlledDrugRegisterReport::canAccess());
    }

    /**
     * A year of the clinic's sales on MySQL, about 100,000 lines: every
     * report over a month and over the year, timed. Opt-in: data is written
     * straight into the tables (copies of real bills, so every column is
     * right), then each report page builds its document as it would on
     * screen.
     */
    public function test_benchmark_a_year_of_sales(): void
    {
        if (! filter_var(getenv('POS_REPORTS_BENCH'), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set POS_REPORTS_BENCH=1 to time the reports on a year of data.');
        }

        $lines = (int) (getenv('POS_REPORTS_BENCH_LINES') ?: 100_000);
        $limit = (float) (getenv('POS_REPORTS_BENCH_MAX_SECONDS') ?: 2.0);

        $started = microtime(true);
        $this->seedAYear($lines);
        fwrite(STDERR, sprintf("\nSeeded %s sale lines in %.1fs\n", number_format($lines), microtime(true) - $started));

        Filament::setCurrentPanel('admin');
        $this->setShopSetting('controlled_register', true);
        $this->actingAs($this->owner);
        $slowest = [];
        $exports = [];

        foreach (['this_month' => ['range' => 'this_month'], 'year' => ['from' => now()->subDays(364)->toDateString(), 'to' => now()->toDateString()]] as $label => $query) {
            foreach (ReportRegistry::PAGES as $class) {
                $page = app($class);
                $page->applyQuery($query);
                $start = microtime(true);
                $this->onScreen($page);
                $seconds = microtime(true) - $start;
                $slowest[] = [$seconds, class_basename($class).' ('.$label.')'];

                $start = microtime(true);
                app(ReportExporter::class)->xlsx($page->document());
                $exports[] = [microtime(true) - $start, class_basename($class).' ('.$label.')'];
                fwrite(STDERR, sprintf("%-32s %-10s screen %5.2fs   Excel %6.2fs\n", class_basename($class), $label, $seconds, end($exports)[0]));
            }
        }

        $dashboard = microtime(true);
        $reports = app(ReportService::class);
        $reports->counters(ReportPeriod::today($this->shop->fresh()));
        $reports->consultations(ReportPeriod::today($this->shop->fresh()));
        $reports->monthComparison();
        $reports->bestSellers(ReportPeriod::thisMonth($this->shop->fresh()), 10);
        $slowest[] = [microtime(true) - $dashboard, 'Dashboard'];
        fwrite(STDERR, sprintf("%-32s %-10s screen %5.2fs\n", 'Dashboard', 'today', microtime(true) - $dashboard));

        rsort($exports);
        fwrite(STDERR, sprintf("Slowest Excel export: %s, %.1fs\n", $exports[0][1], $exports[0][0]));
        rsort($slowest);
        $this->assertLessThanOrEqual($limit, $slowest[0][0], "{$slowest[0][1]} took ".round($slowest[0][0], 2).'s.');
        $this->assertGreaterThanOrEqual($lines, (int) DB::table('sale_items')->count());
    }

    /**
     * What a report's page computes to show itself: a summary report its
     * tables, a table report its rows and total, a paged one its first page
     * and the totals of the whole range.
     */
    private function onScreen(object $page): void
    {
        if ($page instanceof ReportPage && (fn (): bool => static::$isPaginated)->call($page)) {
            (fn () => $this->tableRows(1, 25, null, null))->call($page);
            (fn () => $this->csvTotalRow())->call($page);

            return;
        }

        $page->document();
    }

    /**
     * Copies of a real General Pharmacy bill (three lines) and a real OPD bill
     * at Doctor's Medicines, spread over the last 365 days, with their
     * payments, ledger movements and batches; one bill in 100 partly
     * returned, one in 200 voided; 60 medicines, a goods received note a week.
     */
    private function seedAYear(int $lineCount): void
    {
        $checkout = app(CheckoutService::class);
        $doc = $this->docTill();
        $doctor = $this->cashierAt($this->doctors);
        $consult = $this->consultation(150_000);
        $supplier = $this->supplier();
        $items = [];

        for ($i = 1; $i <= 60; $i++) {
            $item = $this->medicine("Bench Medicine {$i} 500 mg Tablets", [['name' => 'Box of 30', 'factor' => 30, 'price' => 54_000]], schedule: $i % 20 === 0 ? DrugSchedule::Controlled : DrugSchedule::Otc);
            $this->receive($item, "G{$i}", '12/2028', 1_000_000, cost: '33.3333');
            $this->receive($item, "D{$i}", '12/2028', 1_000_000, $this->doctors, '35.5000');
            $items[] = $item;
        }

        $gen = $checkout->checkout(new CheckoutData(device: $this->till, cashier: $this->cashier, lines: [
            new CartLine($items[0]->getKey(), 2), new CartLine($items[1]->getKey(), 1, prescriber: 'Dr. S. Perera'), new CartLine($items[2]->getKey(), 10),
        ], payments: [new PaymentInput(PaymentMethod::Cash, 100_000)], customerName: 'A. Silva'));
        $opd = $checkout->checkout(new CheckoutData(device: $doc, cashier: $doctor, lines: [
            new CartLine($consult->getKey(), 1), new CartLine($items[3]->getKey(), 6), new CartLine($items[4]->getKey(), 4),
        ], payments: [new PaymentInput(PaymentMethod::BankTransfer, 160_000, 'REF')], billType: BillType::Opd, opdMedicinesAmount: 10_000));
        $grn = $this->postReceipt($supplier, [new ReceiptLine($items[0]->getKey(), 10, freeQuantity: 1, unitCost: 1_000, batchNo: 'B1', expiry: '12/2028')], invoiceNo: 'INV-0');

        $templates = [$this->template($gen), $this->template($opd)];
        $ids = fn (string $table): int => (int) DB::table($table)->max('id') + 1;
        $next = ['sales' => $ids('sales'), 'sale_items' => $ids('sale_items'), 'payments' => $ids('payments'), 'stock_movements' => $ids('stock_movements'), 'sale_item_batches' => $ids('sale_item_batches'), 'sale_returns' => $ids('sale_returns'), 'sale_return_items' => $ids('sale_return_items')];
        $batches = DB::table('stock_batches')->get(['id', 'product_variant_id', 'location_id'])->keyBy(fn ($batch): string => $batch->product_variant_id.':'.$batch->location_id);
        $rows = array_fill_keys(array_keys($next), []);
        $start = CarbonImmutable::now()->subDays(364)->startOfDay();
        $span = (int) $start->diffInSeconds(CarbonImmutable::now()->subMinutes(5));
        $bills = intdiv($lineCount, 3);

        for ($n = 0; $n < $bills; $n++) {
            $template = $templates[$n % 5 === 0 ? 1 : 0];
            $at = $start->addSeconds((int) floor($n * $span / max($bills, 1)));
            $saleId = $next['sales']++;
            $voided = $n % 200 === 199;
            $rows['sales'][] = [...$template['sale'], 'id' => $saleId, 'uuid' => (string) Str::uuid(), 'invoice_no' => 'B-'.str_pad((string) $n, 7, '0', STR_PAD_LEFT), 'sold_at' => $at, 'reported_at' => $at, 'created_at' => $at, 'updated_at' => $at,
                'status' => $voided ? 'voided' : 'completed', 'voided_at' => $voided ? $at->addMinutes(5) : null, 'voided_by' => $voided ? $this->owner->getKey() : null, 'void_reason' => $voided ? 'Bench' : null];

            foreach ($template['payments'] as $payment) {
                $rows['payments'][] = [...$payment, 'id' => $next['payments']++, 'sale_id' => $saleId, 'created_at' => $at, 'updated_at' => $at];
            }

            foreach ($template['items'] as $k => $item) {
                $itemId = $next['sale_items']++;
                $variant = $item['tracked'] ? $items[($n * 3 + $k) % 60] : null;
                $line = [...$item['row'], 'id' => $itemId, 'sale_id' => $saleId, 'created_at' => $at, 'updated_at' => $at];

                if ($variant !== null) {
                    $line['product_variant_id'] = $variant->getKey();
                    $line['sku'] = $variant->sku;
                    $movementId = $next['stock_movements']++;
                    $batch = $batches[$variant->getKey().':'.$item['row']['location_id']];
                    $rows['stock_movements'][] = [...$item['movement'], 'id' => $movementId, 'uuid' => (string) Str::uuid(), 'product_variant_id' => $variant->getKey(), 'stock_batch_id' => $batch->id, 'reference_id' => $saleId, 'occurred_at' => $at, 'created_at' => $at];
                    $rows['sale_item_batches'][] = [...$item['batch'], 'id' => $next['sale_item_batches']++, 'sale_item_id' => $itemId, 'stock_batch_id' => $batch->id, 'stock_movement_id' => $movementId, 'created_at' => $at, 'updated_at' => $at];

                    if ($n % 100 === 50 && ! $voided && $template['sale']['bill_type'] === BillType::Standard->value) {
                        $returnId = $next['sale_returns']++;
                        $back = $at->addDays(1);
                        $refund = (int) round($line['line_total'] / max((float) $line['quantity'], 1));
                        $rows['sale_returns'][] = ['id' => $returnId, 'uuid' => (string) Str::uuid(), 'shop_id' => $this->shop->getKey(), 'sale_id' => $saleId, 'user_id' => $this->owner->getKey(), 'return_no' => 'BR-'.$n.'-'.$k, 'reason_code' => ReturnReason::ChangedMind->value, 'refund_total' => $refund, 'refund_method' => 'cash', 'returned_at' => $back, 'created_at' => $back, 'updated_at' => $back];
                        $rows['sale_return_items'][] = ['id' => $next['sale_return_items']++, 'sale_return_id' => $returnId, 'sale_item_id' => $itemId, 'quantity' => 1, 'base_quantity' => 1, 'refund_amount' => $refund, 'restocked' => true, 'created_at' => $back, 'updated_at' => $back];
                        $rows['stock_movements'][] = [...$item['movement'], 'id' => $next['stock_movements']++, 'uuid' => (string) Str::uuid(), 'type' => StockMovementType::Return->value, 'quantity' => 1, 'product_variant_id' => $variant->getKey(), 'stock_batch_id' => $batch->id, 'reference_type' => (new SaleReturn)->getMorphClass(), 'reference_id' => $returnId, 'occurred_at' => $back, 'created_at' => $back];
                    }
                }

                $rows['sale_items'][] = $line;
            }

            if (count($rows['sale_items']) >= 3_000) {
                $this->flush($rows);
            }
        }

        $this->flush($rows);

        // A goods received note a week, copied from a real one.
        $receipt = (array) DB::table('goods_receipts')->where('id', $grn->getKey())->first();
        $receiptItems = DB::table('goods_receipt_items')->where('goods_receipt_id', $grn->getKey())->get()->map(fn ($row): array => (array) $row)->all();

        for ($week = 0; $week < 52; $week++) {
            $on = $start->addWeeks($week)->toDateString();
            $id = DB::table('goods_receipts')->insertGetId([...array_diff_key($receipt, ['id' => 1]), 'uuid' => (string) Str::uuid(), 'grn_number' => 'GRN-B'.$week, 'supplier_invoice_no' => 'INV-B'.$week, 'received_on' => $on]);
            DB::table('goods_receipt_items')->insert(array_map(fn (array $row): array => [...array_diff_key($row, ['id' => 1]), 'goods_receipt_id' => $id], $receiptItems));
        }

        DB::statement('ANALYZE TABLE sales, sale_items, payments, stock_movements, sale_item_batches, sale_returns, sale_return_items, goods_receipts');
    }

    /**
     * A real sale's rows, ready to copy.
     *
     * @return array{sale: array<string, mixed>, payments: list<array<string, mixed>>, items: list<array{row: array<string, mixed>, tracked: bool, movement: array<string, mixed>, batch: array<string, mixed>}>}
     */
    private function template(Sale $sale): array
    {
        $strip = fn (object $row, array $keys): array => array_diff_key((array) $row, array_flip($keys));

        return [
            'sale' => $strip(DB::table('sales')->where('id', $sale->getKey())->first(), ['id', 'uuid', 'invoice_no']),
            'payments' => DB::table('payments')->where('sale_id', $sale->getKey())->get()->map(fn ($row): array => $strip($row, ['id', 'sale_id']))->all(),
            'items' => DB::table('sale_items')->where('sale_id', $sale->getKey())->orderBy('id')->get()->map(function ($item) use ($strip): array {
                $movement = DB::table('stock_movements')->where('reference_type', (new Sale)->getMorphClass())->where('reference_id', $item->sale_id)->where('product_variant_id', $item->product_variant_id)->first();
                $batch = DB::table('sale_item_batches')->where('sale_item_id', $item->id)->first();

                return [
                    'row' => $strip($item, ['id', 'sale_id']),
                    'tracked' => $movement !== null,
                    'movement' => $movement === null ? [] : $strip($movement, ['id', 'uuid']),
                    'batch' => $batch === null ? [] : $strip($batch, ['id', 'sale_item_id', 'stock_batch_id', 'stock_movement_id']),
                ];
            })->all(),
        ];
    }

    /** @param  array<string, list<array<string, mixed>>>  $rows */
    private function flush(array &$rows): void
    {
        foreach ($rows as $table => $batch) {
            foreach (array_chunk($batch, 1_000) as $chunk) {
                DB::table($table)->insert($chunk);
            }

            $rows[$table] = [];
        }
    }
}
