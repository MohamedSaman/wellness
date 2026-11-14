<?php

namespace Tests\Feature\Pos;

use App\Filament\Pages\Reports\ShiftHistoryReport;
use App\Models\AuditLog;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftService;
use App\Services\ShiftSummaryService;
use Filament\Actions\Testing\TestAction;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithReceipts;

/**
 * Reprinting a closed shift's summary (docs/PHARMACY.md §9, branch 17): the
 * till lists its own shifts closed in the last 7 days, a reprint is logged
 * first and printed marked REPRINT (thermal at every width, and A4) with
 * when and by whom, as a sale's reprint is; the first print, from Close
 * shift, stays unmarked. The summary's access rule is unchanged: the
 * shift's cashier, or shifts.manage. The admin's Shift history has Print.
 */
class ShiftSummaryReprintTest extends BatchTestCase
{
    use WorksWithCheckout, WorksWithReceipts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockTheClinic();
    }

    /** A shift on T1 with one bill (1,065.00 by bank transfer), closed $hours later. */
    private function closedShift(?User $cashier = null, int $hours = 8): Shift
    {
        $shifts = app(ShiftService::class);
        $shift = $shifts->open($this->till, $cashier ?? $this->cashier, 500_000);
        $this->billAtGeneral();
        $this->travel($hours)->hours();

        return $shifts->close($shift, $cashier ?? $this->cashier, 500_000);
    }

    /** @return array<string, mixed> */
    private function reprint(Shift $shift, ?User $user = null, string $format = 'thermal'): array
    {
        return $this->actingAs($user ?? $this->cashier)
            ->postJson(route('pos.shift.reprint', ['shift' => $shift->uuid]), ['device' => $this->till->uuid, 'format' => $format])
            ->assertOk()
            ->json();
    }

    /** The thermal page's lines, as the browser prints them (large print, 36 columns). */
    private function printedAt(string $url, ?User $user = null): string
    {
        $lines = null;

        $this->actingAs($user ?? $this->cashier)->get($url)->assertOk()
            ->assertInertia(function (Assert $page) use (&$lines): void {
                $page->component('pos/Receipt')->where('thermal.columns', 36);
                $lines = $page->toArray()['props']['thermal']['lines'];
            });

        return implode("\n", array_column((array) $lines, 'text'));
    }

    public function test_the_till_lists_only_its_own_shifts_closed_in_the_last_7_days(): void
    {
        $shifts = app(ShiftService::class);
        $old = $this->closedShift();                       // closed 2026-10-18 18:00
        $this->travel(7)->days();
        $this->travel(1)->minutes();                       // now 2026-10-25 18:01: 7 days and a minute since
        $recent = $this->closedShift(hours: 1);            // closed 2026-10-25 19:01, one bill

        // Another cashier's shift on this till, and a shift on another till.
        $other = $this->cashierAt();
        $theirs = $shifts->close($shifts->open($this->till, $other, 0), $other, 0);
        $doc = $this->cashierAt($this->doctors);
        $atDoc = $shifts->close($shifts->open($this->docTill(), $doc, 0), $doc, 0);
        // An open shift is not listed.
        $shifts->open($this->till, $this->cashier, 0);

        $this->actingAs($this->cashier)
            ->getJson(route('pos.shift.closed', ['device' => $this->till->uuid]))
            ->assertOk()
            ->assertExactJson([
                'days' => 7,
                'shifts' => [
                    ['uuid' => $recent->uuid, 'closed_at' => '2026-10-25 19:01', 'cashier' => 'Clinic Cashier', 'net' => 106_500],
                ],
            ]);

        // shifts.manage sees every cashier's shift on this till, newest first; still not the other till's, nor the old one.
        $this->actingAs($this->owner)
            ->getJson(route('pos.shift.closed', ['device' => $this->till->uuid]))
            ->assertOk()
            ->assertJsonPath('shifts.*.uuid', [$theirs->uuid, $recent->uuid]);
        $this->assertNotNull($old->closed_at);
        $this->assertNotNull($atDoc->closed_at);
    }

    public function test_a_reprint_is_logged_and_marked_while_the_first_print_is_not(): void
    {
        $shift = $this->closedShift();
        $this->travel(2)->days();                          // 2026-10-20 18:00 Colombo

        $first = $this->printedAt(route('pos.shift.summary', ['shift' => $shift->uuid]));
        $this->assertStringNotContainsString('REPRINT', $first);
        $this->assertSame(0, AuditLog::query()->where('event', ShiftSummaryService::REPRINT_EVENT)->count());

        $url = $this->reprint($shift)['summary_url'];
        $entry = AuditLog::query()->where('event', ShiftSummaryService::REPRINT_EVENT)->sole();

        $this->assertLessThanOrEqual(30, strlen(ShiftSummaryService::REPRINT_EVENT));
        $this->assertSame($this->cashier->getKey(), (int) $entry->user_id);
        $this->assertSame($this->shop->getKey(), (int) $entry->shop_id);
        $this->assertSame([$shift->getMorphClass(), $shift->getKey()], [$entry->auditable_type, (int) $entry->auditable_id]);
        $this->assertSameIgnoringKeyOrder([
            'shift_id' => $shift->getKey(),
            'shift_uuid' => $shift->uuid,
            'format' => 'thermal',
            'source' => 'till',
            'device_id' => $this->till->getKey(),
            'till' => 'T1',
        ], $entry->new_values);
        $this->assertSame(route('pos.shift.reprinted', ['shift' => $shift->uuid, 'reprint' => $entry->getKey()]), $url);

        $printed = $this->printedAt($url);
        $lines = explode("\n", $printed);
        $this->assertSame('*** REPRINT ***', trim($lines[0]));
        $this->assertSame(['REPRINT 20/10/2026 18:00', 'by Clinic Cashier'], array_map('trim', array_slice($lines, -2)));
        $this->assertSame(str_repeat('=', 36), $lines[count($lines) - 3]);
        // Every figure as the first print had it; only the marker and the Printed time differ.
        $this->assertStringContainsString('NET SALES LKR               1,065.00', $printed);
        $this->assertStringContainsString('Printed             20/10/2026 18:00', $printed);
        $this->assertLinesFit($printed, 36);

        // Opening the reprint again shows the same entry: no new audit row.
        $this->printedAt($url);
        $this->assertSame(1, AuditLog::query()->where('event', ShiftSummaryService::REPRINT_EVENT)->count());
    }

    public function test_the_reprint_marker_fits_48_and_32_columns(): void
    {
        $shift = $this->closedShift();
        $entry = app(ShiftSummaryService::class)->recordReprint($shift, $this->owner, null, 'thermal');
        $payload = app(ShiftSummaryService::class)->thermalPayload($shift->load(['device.location', 'user']), $entry);

        foreach ([80 => 48, 58 => 32] as $paper => $columns) {
            $lines = explode("\n", $this->printed('shift', $payload, $paper));

            $this->assertSame('*** REPRINT ***', trim($lines[0]), "{$paper} mm");
            $this->assertSame(str_repeat('=', $columns), $lines[count($lines) - 3], "{$paper} mm");
            $this->assertSame(['REPRINT 18/10/2026 18:00', 'by Clinic Owner'], array_map('trim', array_slice($lines, -2)), "{$paper} mm");
            $this->assertLinesFit(implode("\n", $lines), $columns);
        }

        $this->assertStringNotContainsString('REPRINT', $this->printed('shift', app(ShiftSummaryService::class)->thermalPayload($shift)));
    }

    public function test_an_a4_reprint_is_marked_and_the_a4_first_print_is_not(): void
    {
        $shift = $this->closedShift();

        $this->actingAs($this->cashier)->get(route('pos.shift.summary', ['shift' => $shift->uuid, 'format' => 'a4']))
            ->assertOk()
            ->assertDontSee('REPRINT')
            ->assertDontSee('afterprint');

        $url = $this->reprint($shift, format: 'a4')['summary_url'];
        $this->assertSame('a4', AuditLog::query()->where('event', ShiftSummaryService::REPRINT_EVENT)->sole()->new_values['format']);

        $this->actingAs($this->cashier)->get($url.'?print=1')
            ->assertOk()
            ->assertSee('*** REPRINT ***')
            ->assertSee('Reprinted 18/10/2026 18:00 by Clinic Cashier')
            ->assertSee('data-test="shift-takings"', false)
            ->assertSee('afterprint', false);
    }

    public function test_another_cashier_without_shifts_manage_is_refused(): void
    {
        $shift = $this->closedShift();
        $other = $this->cashierAt();

        $this->actingAs($other)
            ->postJson(route('pos.shift.reprint', ['shift' => $shift->uuid]), ['device' => $this->till->uuid])
            ->assertForbidden();
        $this->assertSame(0, AuditLog::query()->where('event', ShiftSummaryService::REPRINT_EVENT)->count());

        // A reprint opens only for whoever reprinted it.
        $url = $this->reprint($shift)['summary_url'];
        $this->actingAs($other)->get($url)->assertNotFound();
        $this->actingAs($this->owner)->get($url)->assertNotFound();

        // shifts.manage (the owner) may reprint anyone's shift.
        $this->reprint($shift, $this->owner);
        $this->assertSame(2, AuditLog::query()->where('event', ShiftSummaryService::REPRINT_EVENT)->count());
    }

    public function test_an_open_shift_or_an_unknown_format_is_not_reprinted(): void
    {
        $shift = app(ShiftService::class)->open($this->till, $this->cashier, 0);

        $this->actingAs($this->cashier)
            ->postJson(route('pos.shift.reprint', ['shift' => $shift->uuid]), ['device' => $this->till->uuid])
            ->assertUnprocessable();
        $shift = app(ShiftService::class)->close($shift, $this->cashier, 0);
        $this->actingAs($this->cashier)
            ->postJson(route('pos.shift.reprint', ['shift' => $shift->uuid]), ['device' => $this->till->uuid, 'format' => 'pdf'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('format');
        $this->assertSame(0, AuditLog::query()->where('event', ShiftSummaryService::REPRINT_EVENT)->count());
    }

    public function test_shift_history_prints_a_shift_for_the_owner(): void
    {
        $shift = $this->closedShift();
        $open = app(ShiftService::class)->open($this->till, $this->cashier, 0);
        $this->actingAs($this->owner);

        Livewire::withQueryParams(['range' => 'this_month'])->test(ShiftHistoryReport::class)
            ->assertActionVisible(TestAction::make('printSummary')->table($shift->uuid))
            ->assertActionHidden(TestAction::make('printSummary')->table($open->uuid))
            ->mountAction(TestAction::make('printSummary')->table($shift->uuid))
            ->assertSchemaStateSet(['format' => 'thermal'])  // the receipt printer first, A4 the other choice
            ->fillForm(['format' => 'a4'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Shift summary sent to print');

        $entry = AuditLog::query()->where('event', ShiftSummaryService::REPRINT_EVENT)->sole();
        $this->assertSame($this->owner->getKey(), (int) $entry->user_id);
        $this->assertSame(['a4', 'admin', null], [$entry->new_values['format'], $entry->new_values['source'], $entry->new_values['device_id']]);

        $this->get(route('pos.shift.reprinted', ['shift' => $shift->uuid, 'reprint' => $entry->getKey()]))
            ->assertOk()
            ->assertSee('Reprinted 18/10/2026 18:00 by Clinic Owner');
    }

    public function test_shift_history_hides_print_from_a_user_who_may_not_read_the_shift(): void
    {
        $shift = $this->closedShift();
        $viewer = $this->cashierAt();
        $viewer->givePermissionTo('reports.view');
        $this->actingAs($viewer);

        Livewire::withQueryParams(['range' => 'this_month'])->test(ShiftHistoryReport::class)
            ->assertActionHidden(TestAction::make('printSummary')->table($shift->uuid));
    }

    private function assertLinesFit(string $printed, int $columns): void
    {
        foreach (explode("\n", $printed) as $line) {
            $this->assertLessThanOrEqual($columns, strlen($line), "Too wide for {$columns} columns: [{$line}]");
        }
    }
}
