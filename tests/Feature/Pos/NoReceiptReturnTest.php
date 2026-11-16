<?php

namespace Tests\Feature\Pos;

use App\Enums\StockMovementType;
use App\Exceptions\PosException;
use App\Filament\Pages\Reports\DailySummaryReport;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\NoReceiptReturn;
use App\Models\Shift;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Data\NoReceiptLine;
use App\Services\GoLiveResetService;
use App\Services\NoReceiptReturnService;
use App\Services\ReportService;
use App\Services\ShiftService;
use App\Services\ShiftSummaryService;
use App\Support\ReportPeriod;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithReceipts;

/**
 * Returns without a receipt (docs/PHARMACY.md, branch 18): the owner
 * refunds, in cash from the till's drawer, an item bought before the system
 * went live; the stock goes to Quarantine (batch known or not) or back on
 * sale at the till's location (batch and expiry required, never expired),
 * at the location's average cost. The refund comes off the shift's
 * expected cash, shows as "of which without receipt", counts as a refund
 * on the daily summary, prints a slip and is audited.
 */
class NoReceiptReturnTest extends BatchTestCase
{
    use WorksWithCheckout, WorksWithReceipts;

    private Shift $shift;

    private Location $quarantine;

    private NoReceiptReturnService $returns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockTheClinic();             // Panadol PN2611 03/2027, 60 tablets at GEN, cost 10.00
        $this->shift = app(ShiftService::class)->open($this->till, $this->owner, 500_000);
        $this->quarantine = Location::query()->where('code', 'QUAR')->firstOrFail();
        $this->returns = app(NoReceiptReturnService::class);
    }

    /** @param list<NoReceiptLine> $lines */
    private function refund(array $lines, ?User $user = null, string $reason = 'Bought before the system went live'): NoReceiptReturn
    {
        return $this->returns->create($this->till, $user ?? $this->owner, $lines, $reason, 'Took Amoxil instead');
    }

    private function level(Location $at): ?StockLevel
    {
        return StockLevel::query()->where('product_variant_id', $this->panadol->getKey())->where('location_id', $at->getKey())->first();
    }

    public function test_a_return_to_quarantine_with_the_batch_unknown_refunds_the_tablet_price(): void
    {
        $return = $this->refund([new NoReceiptLine($this->panadol->getKey(), 1)]); // 1 tablet at 20.00

        $this->assertSame('NR-000001', $return->number);
        $this->assertSame(2_000, $return->refund_total);
        $this->assertSame('cash', $return->refund_method->value);
        $item = $return->items->sole();
        $this->assertSame([2_000, 2_000, 2_000], [$item->list_price, $item->unit_value, $item->refund_amount]);
        $this->assertSame((int) $this->quarantine->getKey(), (int) $item->location_id);
        $this->assertNull($item->batch_no);

        // Into Quarantine's unallocated batch, at the item's cost; nothing at General Pharmacy changes.
        $this->assertSame(1.0, (float) $this->level($this->quarantine)?->quantity);
        $this->assertSame(1.0, (float) $this->unallocated($this->panadol, $this->quarantine)?->quantity);
        $this->assertSame(60.0, (float) $this->level($this->general)?->quantity);
        $movement = StockMovement::query()->where('reference_type', $return->getMorphClass())->sole();
        $this->assertSame(StockMovementType::Return, $movement->type);
        $this->assertSame((int) $this->till->getKey(), (int) $movement->device_id);
        $this->assertLedgerMatches();

        // The drawer: 5,000.00 - 20.00.
        $this->assertSame(498_000, app(ShiftService::class)->expectedCash($this->shift));
        $summary = app(ShiftService::class)->summary($this->shift);
        $this->assertSame([2_000, 1, 2_000, 1, -2_000], [$summary['refunds'], $summary['refunds_count'], $summary['no_receipt_refunds'], $summary['no_receipt_count'], $summary['net']]);
        $this->assertSame(2_000, $summary['cash']['refunds']);
    }

    public function test_back_on_sale_needs_the_batch_and_its_expiry_and_keeps_the_average(): void
    {
        $box = $this->unitNamed($this->panadol, 'Box of 30');
        $averageBefore = (string) $this->level($this->general)?->average_cost;

        try {
            $this->refund([new NoReceiptLine($this->panadol->getKey(), 1, $box, toQuarantine: false)]);
            $this->fail('Back on sale without a batch.');
        } catch (PosException $e) {
            $this->assertStringContainsString('enter the batch number and expiry printed on the pack', $e->getMessage());
        }

        try {
            $this->refund([new NoReceiptLine($this->panadol->getKey(), 1, $box, toQuarantine: false, batchNo: 'OLD1', expiry: '09/2026')]);
            $this->fail('Expired stock back on sale.');
        } catch (PosException $e) {
            $this->assertStringContainsString('has expired: send it to Quarantine', $e->getMessage());
        }

        $this->assertSame(0, NoReceiptReturn::query()->count());

        // A box at 500.00 (typed below its 540.00 price), into the batch already on the shelf.
        $return = $this->refund([new NoReceiptLine($this->panadol->getKey(), 1, $box, unitValue: 50_000, toQuarantine: false, batchNo: 'pn2611', expiry: '03/2027')]);

        $item = $return->items->sole();
        $this->assertSame([54_000, 50_000, 50_000], [$item->list_price, $item->unit_value, $item->refund_amount]);
        $this->assertSame(['PN2611', '2027-03-31', 30.0], [$item->batch_no, $item->expiry_date?->toDateString(), (float) $item->base_quantity]);
        $this->assertSame(90.0, (float) $this->batch($this->panadol, 'PN2611')->quantity);
        $this->assertSame($averageBefore, (string) $this->level($this->general)?->average_cost);
        $this->assertSame(450_000, app(ShiftService::class)->expectedCash($this->shift));
        $this->assertLedgerMatches();
    }

    public function test_quarantine_takes_an_expired_batch_and_an_item_without_batches_goes_through_the_ledger(): void
    {
        $dressing = $this->openPriceItem('Wound dressing', 50_000, 150_000, tracksStock: true);

        $return = $this->refund([
            new NoReceiptLine($this->panadol->getKey(), 10, batchNo: 'OLD1', expiry: '09/2026'),
            new NoReceiptLine($dressing->getKey(), 2, toQuarantine: false),
        ]);

        $this->assertSame(10 * 2_000 + 2 * 50_000, $return->refund_total);
        $this->assertSame(10.0, (float) $this->batch($this->panadol, 'OLD1', $this->quarantine)->quantity);
        $this->assertSame(2.0, $dressing->fresh()?->stockAt($this->general));
        $this->assertLedgerMatches();
    }

    public function test_the_rules_are_checked_in_the_service(): void
    {
        $refused = function (callable $make, string $message): void {
            try {
                $make();
                $this->fail("Expected: {$message}");
            } catch (PosException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        };

        $refused(fn () => $this->refund([new NoReceiptLine($this->panadol->getKey(), 1)], $this->cashier), 'not allowed to refund items without a receipt');
        $refused(fn () => $this->refund([new NoReceiptLine($this->panadol->getKey(), 1)], reason: ' '), 'Give the reason');
        $refused(fn () => $this->refund([]), 'Add the item');
        $refused(fn () => $this->refund([new NoReceiptLine($this->panadol->getKey(), 0.5)]), 'whole units');
        $refused(fn () => $this->refund([new NoReceiptLine($this->panadol->getKey(), 0)]), 'greater than zero');
        $refused(fn () => $this->refund([new NoReceiptLine($this->consultation()->getKey(), 1)]), 'is a service');
        $refused(fn () => $this->refund([new NoReceiptLine($this->panadol->getKey(), 1, batchNo: 'X1')]), 'or neither');

        app(ShiftService::class)->close($this->shift, $this->owner, 500_000);
        $refused(fn () => $this->refund([new NoReceiptLine($this->panadol->getKey(), 1)]), 'Open a shift on this till first');

        $this->assertSame(0, NoReceiptReturn::query()->count());
    }

    public function test_it_is_audited_and_prints_a_slip_marked_on_reprint(): void
    {
        $return = $this->refund([new NoReceiptLine($this->panadol->getKey(), 3, batchNo: 'OLD1', expiry: '09/2026')]);

        $created = AuditLog::query()->where('event', 'no_receipt_return_created')->sole();
        $this->assertSame((int) $this->owner->getKey(), (int) $created->user_id);
        $this->assertSame(['NR-000001', 6_000, 'Bought before the system went live', 'T1'], [$created->new_values['number'], $created->new_values['refund_total'], $created->new_values['reason'], $created->new_values['till']]);
        $this->assertSame('OLD1', $created->new_values['items'][0]['batch_no']);

        $first = $this->page(route('pos.no-receipt-returns.slip', $return));
        $this->assertStringNotContainsString('REPRINT', $first);
        $this->assertStringContainsString('*** REFUND - NO RECEIPT ***', $first);
        $this->assertMatchesRegularExpression('/^Refund no\.\s+NR-000001$/m', $first);
        $this->assertStringContainsString('Panadol Tablets', $first);
        $this->assertMatchesRegularExpression('/^\s+3 Tablet x 20\.00\s+60\.00$/m', $first);
        $this->assertStringContainsString('To Quarantine / Expired', $first);
        $this->assertStringContainsString('Batch OLD1 exp 09/2026', $first);
        $this->assertMatchesRegularExpression('/^REFUND TOTAL LKR\s+60\.00$/m', $first);
        $this->assertMatchesRegularExpression('/^Cash\s+60\.00$/m', $first);
        $this->assertStringContainsString('Reason: Bought before the system went live', (string) preg_replace('/\s+/', ' ', $first));
        $this->assertMatchesRegularExpression("/^Customer's signature _+$/m", $first);

        $this->travel(5)->minutes();
        $url = $this->actingAs($this->owner)
            ->postJson(route('pos.no-receipt-returns.reprint', $return), ['device' => $this->till->uuid])
            ->assertOk()->json('slip_url');
        $this->assertSame(1, AuditLog::query()->where('event', 'no_receipt_return_reprinted')->count());

        $reprint = $this->page($url);
        $this->assertStringStartsWith('*** REPRINT ***', trim($reprint));
        $this->assertStringContainsString('REPRINT 18/10/2026 10:05', $reprint);

        foreach ([48 => 80, 32 => 58] as $columns => $paper) {
            $printed = $this->printed('no_receipt', $this->returns->slip($return), $paper);
            $this->assertStringContainsString('REFUND - NO RECEIPT', $printed);

            foreach (explode("\n", $printed) as $line) {
                $this->assertLessThanOrEqual($columns, strlen($line), "{$columns} columns: {$line}");
            }
        }

        $this->actingAs($this->cashierAt())->get(route('pos.no-receipt-returns.slip', $return))->assertForbidden();
    }

    public function test_the_till_records_a_return_and_lists_the_shifts_returns(): void
    {
        $this->actingAs($this->owner)->postJson(route('pos.no-receipt-returns.store'), [
            'device' => $this->till->uuid,
            'reason' => 'Pre-system purchase',
            'lines' => [['variant_id' => $this->panadol->getKey(), 'quantity' => 2, 'unit_value' => '15.00', 'to' => 'quarantine']],
        ])->assertCreated()
            ->assertJsonPath('return.number', 'NR-000001')
            ->assertJsonPath('return.refund_total', 3_000);

        $this->actingAs($this->owner)
            ->getJson(route('pos.no-receipt-returns.index', ['device' => $this->till->uuid]))
            ->assertOk()
            ->assertJsonPath('quarantine', 'Quarantine / Expired')
            ->assertJsonPath('returns.0.number', 'NR-000001')
            ->assertJsonPath('returns.0.refund_total', 3_000);

        $this->actingAs($this->owner)->postJson(route('pos.no-receipt-returns.store'), ['device' => $this->till->uuid, 'lines' => []])
            ->assertJsonValidationErrors(['reason', 'lines']);
        $this->actingAs($this->cashier)->postJson(route('pos.no-receipt-returns.store'), [
            'device' => $this->till->uuid,
            'reason' => 'x',
            'lines' => [['variant_id' => $this->panadol->getKey(), 'quantity' => 1, 'to' => 'quarantine']],
        ])->assertStatus(422)->assertJsonPath('message', 'You are not allowed to refund items without a receipt.');

        $this->actingAs($this->owner)->withCookie('pos_till', $this->till->uuid)->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page->where('noReceiptReturns', true));
        $this->actingAs($this->cashier)->withCookie('pos_till', $this->till->uuid)->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page->where('noReceiptReturns', false));
    }

    public function test_the_daily_summary_counts_it_as_a_medicines_refund_at_the_counter_that_paid_it(): void
    {
        $this->refund([new NoReceiptLine($this->panadol->getKey(), 1)]);

        $period = ReportPeriod::today($this->shop->fresh());
        $reports = app(ReportService::class);
        $general = collect($reports->counters($period))->firstWhere('location_id', $this->general->getKey());
        $this->assertSame([2_000, -2_000, 2_000, -2_000], [$general['refunds'], $general['net'], $general['goods_refunds'], $general['goods']]);
        $this->assertSame([0, 2_000], [collect($reports->paymentMethods($period))->firstWhere('key', 'cash')['taken'], collect($reports->paymentMethods($period))->firstWhere('key', 'cash')['refunds']]);
        $this->assertSame(0, collect($reports->counters($period, (int) $this->doctors->getKey()))->sum('refunds'));
        $this->assertSame(2_000, collect($reports->shiftCash($period))->firstWhere('till', 'T1')['cash_refunds']);

        // On the shift summary: under the refunds.
        $printed = $this->printed('shift', app(ShiftSummaryService::class)->thermalPayload($this->shift));
        $this->assertMatchesRegularExpression('/^Refunds \(1\)\s+-20\.00$/m', $printed);
        $this->assertMatchesRegularExpression('/^  of which without receipt \(1\)\s+-20\.00$/m', $printed);

        $this->actingAs($this->owner);
        Livewire::test(DailySummaryReport::class)->assertSee('returns without a receipt included');
    }

    public function test_go_live_reset_removes_the_rehearsals_returns_and_their_stock(): void
    {
        $this->refund([new NoReceiptLine($this->panadol->getKey(), 4)]);
        app(ShiftService::class)->close($this->shift, $this->owner, 492_000);

        $removed = app(GoLiveResetService::class)->reset($this->shop, $this->owner, false);

        $this->assertSame(1, $removed['no_receipt_returns']);
        $this->assertSame(0, NoReceiptReturn::query()->count());
        $this->assertSame(0.0, (float) $this->level($this->quarantine)?->quantity);
        $this->assertLedgerMatches();
    }

    /** The lines of a slip page as the browser prints them (large print, 36 columns). */
    private function page(string $url): string
    {
        $lines = null;

        $this->actingAs($this->owner)->get($url)->assertOk()
            ->assertInertia(function (Assert $page) use (&$lines): void {
                $page->component('pos/Receipt')->where('thermal.columns', 36);
                $lines = $page->toArray()['props']['thermal']['lines'];
            });

        return implode("\n", array_column((array) $lines, 'text'));
    }
}
