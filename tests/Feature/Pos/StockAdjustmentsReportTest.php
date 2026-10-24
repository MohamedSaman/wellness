<?php

namespace Tests\Feature\Pos;

use App\Enums\AdjustmentReason;
use App\Services\Data\WriteOffLine;
use App\Services\ReportService;
use App\Services\StockCountService;
use App\Services\StockWriteOffService;
use App\Support\ReportPeriod;

/**
 * The stock adjustments report hook (docs/PHARMACY.md §9, branch 10, for the
 * reports branch): adjustments by reason and by source (write-off note,
 * stock count, by hand), quantities in and out and value at the ledger's
 * cost; write-off and count numbers as references in the movements report.
 */
class StockAdjustmentsReportTest extends BatchTestCase
{
    public function test_adjustments_are_grouped_by_reason_and_source_with_their_value(): void
    {
        $panadol = $this->medicine();
        $old = $this->foundExpired($panadol, 'OLD', '2026-09-30', 4);        // by hand, count correction in: 4 x 1,000
        $this->receive($panadol, 'A', '2027-06-30', 20, cost: 1_000);
        app(StockWriteOffService::class)->writeOff([new WriteOffLine((int) $old->getKey())], AdjustmentReason::Expired, $this->owner);
        $count = app(StockCountService::class)->start($this->general, $this->owner);
        app(StockCountService::class)->enter($count->lines()->where('batch_no', 'A')->sole(), 18, null, $this->owner);
        app(StockCountService::class)->post($count, $this->owner);

        $period = ReportPeriod::between($this->shop, '2026-10-18', '2026-10-18');
        $rows = app(ReportService::class)->stockAdjustments($period);

        $this->assertSame([
            ['reason' => 'count_correction', 'label' => 'Count correction', 'source' => 'count', 'movements' => 1, 'quantity_in' => 0.0, 'quantity_out' => 2.0, 'value' => -2_000],
            ['reason' => 'count_correction', 'label' => 'Count correction', 'source' => 'manual', 'movements' => 1, 'quantity_in' => 4.0, 'quantity_out' => 0.0, 'value' => 4_000],
            ['reason' => 'expired', 'label' => 'Expired', 'source' => 'write_off', 'movements' => 1, 'quantity_in' => 0.0, 'quantity_out' => 4.0, 'value' => -4_000],
        ], $rows);

        $this->assertSame([], app(ReportService::class)->stockAdjustments($period, (int) $this->doctors->getKey()));
        $this->assertSame([], app(ReportService::class)->stockAdjustments(ReportPeriod::between($this->shop, '2026-10-17', '2026-10-17')));

        $references = collect(app(ReportService::class)->stockMovements($period, false, 1, 50)->items())->pluck('reference')->all();
        $this->assertContains('WO-000001', $references);
        $this->assertContains('SC-000001', $references);
        $this->assertContains('Manual', $references);
    }
}
