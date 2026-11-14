<?php

namespace Tests\Feature\Pos;

use App\Filament\Pages\Reports\ShiftHistoryReport;
use App\Models\AuditLog;
use App\Services\ShiftService;
use App\Services\ShiftSummaryService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * Reprinting a shift summary is a pharmacy feature (docs/PHARMACY.md,
 * branch 17): a retail shop keeps its A4 summary as it was, with no
 * reprint endpoints, no marker and no Print on Shift history.
 */
class ShiftSummaryReprintRetailTest extends PosTestCase
{
    public function test_a_retail_shop_has_no_shift_summary_reprints(): void
    {
        $shifts = app(ShiftService::class);
        $shift = $shifts->close($shifts->open($this->till, $this->cashier, 10_000), $this->cashier, 10_000);

        $this->actingAs($this->cashier)
            ->getJson(route('pos.shift.closed', ['device' => $this->till->uuid]))
            ->assertNotFound();
        $this->actingAs($this->cashier)
            ->postJson(route('pos.shift.reprint', ['shift' => $shift->uuid]), ['device' => $this->till->uuid])
            ->assertNotFound();
        $this->actingAs($this->cashier)
            ->get(route('pos.shift.reprinted', ['shift' => $shift->uuid, 'reprint' => 1]))
            ->assertNotFound();
        $this->assertSame(0, AuditLog::query()->where('event', ShiftSummaryService::REPRINT_EVENT)->count());

        // The summary page is the A4 page it always was.
        $this->actingAs($this->cashier)->get(route('pos.shift.summary', ['shift' => $shift->uuid, 'print' => 1]))
            ->assertOk()
            ->assertViewIs('pos.shift-summary')
            ->assertDontSee('REPRINT')
            ->assertDontSee('afterprint');

        $this->actingAs($this->owner);
        Livewire::withQueryParams(['range' => 'this_month'])->test(ShiftHistoryReport::class)
            ->assertActionDoesNotExist(TestAction::make('printSummary')->table($shift->uuid));
    }
}
