<?php

namespace Tests\Feature\Pos;

use App\Enums\CashMovementCategory;
use App\Enums\CashMovementType;
use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\Shift;
use App\Models\User;
use App\Services\CashMovementService;
use App\Services\ShiftService;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithReceipts;

/**
 * Cash in and out at the till (docs/PHARMACY.md, branch 18): the till's
 * own open shift's movements, what the user may record and up to how much,
 * a movement made from the till with its slip, reprints logged and marked
 * REPRINT at 48, 32 and 36 (large print) columns, voids by the owner, and
 * the page's flags.
 */
class CashMovementTillTest extends BatchTestCase
{
    use WorksWithCheckout, WorksWithReceipts;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockTheClinic();
        $this->shift = app(ShiftService::class)->open($this->till, $this->cashier, 500_000);
    }

    /** @param array<string, mixed> $body */
    private function record(array $body, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->cashier)->postJson(route('pos.cash-movements.store'), [
            'device' => $this->till->uuid,
            ...$body,
        ]);
    }

    /** The lines of a slip page as the browser prints them (large print, 36 columns). */
    private function page(string $url, ?User $user = null): string
    {
        $lines = null;

        $this->actingAs($user ?? $this->cashier)->get($url)->assertOk()
            ->assertInertia(function (Assert $page) use (&$lines): void {
                $page->component('pos/Receipt')->where('thermal.columns', 36);
                $lines = $page->toArray()['props']['thermal']['lines'];
            });

        return implode("\n", array_column((array) $lines, 'text'));
    }

    public function test_the_till_lists_its_open_shifts_movements_and_what_the_user_may_record(): void
    {
        $movements = app(CashMovementService::class);
        $mine = $movements->create($this->till, $this->cashier, CashMovementType::PaidOut, 1_000, CashMovementCategory::RefundDifference);

        // Another till's shift, and this till's earlier closed shift, are not listed.
        $opd = $this->docTill();
        $docCashier = $this->cashierAt($this->doctors);
        app(ShiftService::class)->open($opd, $docCashier, 0);
        $movements->create($opd, $docCashier, CashMovementType::PaidIn, 5_000, CashMovementCategory::FloatTopUp);

        $response = $this->actingAs($this->cashier)
            ->getJson(route('pos.cash-movements.index', ['device' => $this->till->uuid]))
            ->assertOk()
            ->assertJsonPath('shift.uuid', $this->shift->uuid)
            ->assertJsonCount(1, 'movements')
            ->assertJsonPath('movements.0.uuid', $mine->uuid)
            ->assertJsonPath('movements.0.number', 'CM-000001')
            ->assertJsonPath('movements.0.category', 'Refund difference')
            ->assertJsonPath('movements.0.at', '2026-10-18 10:00')
            ->assertJsonPath('can_void', false);

        $this->assertSame(['paid_out', 'paid_in', 'doctor_payment'], array_column($response->json('types'), 'value'));
        $this->assertSame([200_000, null, 2_500_000], array_column($response->json('types'), 'limit'));
        $this->assertSame(['refund_difference', 'expense', 'other'], array_column($response->json('types.0.categories'), 'value'));
        $this->assertSame([], $response->json('types.2.categories'));

        $this->actingAs($this->owner)
            ->getJson(route('pos.cash-movements.index', ['device' => $this->till->uuid]))
            ->assertJsonPath('can_void', true)
            ->assertJsonPath('types.0.limit', null);
    }

    public function test_a_paid_out_from_the_till_records_it_and_prints_its_slip(): void
    {
        $response = $this->record(['type' => 'paid_out', 'amount' => '10', 'category' => 'refund_difference', 'note' => 'Returned syrup, took Panadol'])
            ->assertCreated()
            ->assertJsonPath('movement.number', 'CM-000001')
            ->assertJsonPath('movement.amount', 1_000);

        $movement = CashMovement::query()->sole();
        $this->assertSame(route('pos.cash-movements.slip', $movement), $response->json('slip_url'));
        $this->assertSame(499_000, app(ShiftService::class)->expectedCash($this->shift));

        $slip = $this->page($response->json('slip_url'));
        $this->assertStringNotContainsString('REPRINT', $slip);
        $this->assertStringContainsString('PAID OUT', $slip);
        $this->assertMatchesRegularExpression('/^No\.\s+CM-000001$/m', $slip);
        $this->assertMatchesRegularExpression('/^Date\s+18\/10\/2026 10:00$/m', $slip);
        $this->assertMatchesRegularExpression('/^Till\s+T1/m', $slip);
        $this->assertMatchesRegularExpression('/^Location\s+General Pharmacy$/m', $slip);
        $this->assertMatchesRegularExpression('/^For\s+Refund difference$/m', $slip);
        $this->assertMatchesRegularExpression('/^Paid by\s+'.preg_quote($this->cashier->name, '/').'$/m', $slip);
        $this->assertMatchesRegularExpression('/^AMOUNT LKR\s+10\.00$/m', $slip);
        $this->assertStringContainsString('Note: Returned syrup, took Panadol', $slip);
        $this->assertMatchesRegularExpression('/^Received by _+$/m', $slip);
    }

    public function test_a_paid_in_slip_says_who_took_it_in(): void
    {
        $url = $this->record(['type' => 'paid_in', 'amount' => '2,000.00', 'category' => 'float_top_up'])->assertCreated()->json('slip_url');

        $slip = $this->page($url);
        $this->assertStringContainsString('PAID IN', $slip);
        $this->assertMatchesRegularExpression('/^Taken in by\s+/m', $slip);
        $this->assertMatchesRegularExpression('/^AMOUNT LKR\s+2,000\.00$/m', $slip);
        $this->assertMatchesRegularExpression('/^Given by _+$/m', $slip);
    }

    public function test_the_servers_rules_come_back_as_messages(): void
    {
        $this->record(['type' => 'paid_out', 'amount' => '5000', 'category' => 'expense'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The most you may pay out at once is LKR 2,000.00. Ask the owner.');
        $this->record(['type' => 'paid_out', 'amount' => '100', 'category' => 'other'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Say what the cash was for in the note.');
        $this->record(['type' => 'paid_out', 'amount' => 'ten'])->assertJsonValidationErrors('amount');
        $this->record(['type' => 'lunch', 'amount' => '10'])->assertJsonValidationErrors('type');
        $this->record(['type' => 'doctor_payment', 'amount' => '10', 'doctor' => 999])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That doctor was not found.');

        $this->assertSame(0, CashMovement::query()->count());
    }

    public function test_a_till_without_an_open_shift_records_nothing(): void
    {
        app(ShiftService::class)->close($this->shift, $this->cashier, 500_000);

        $this->actingAs($this->cashier)
            ->getJson(route('pos.cash-movements.index', ['device' => $this->till->uuid]))
            ->assertJsonPath('shift', null)
            ->assertJsonPath('movements', []);
        $this->record(['type' => 'paid_in', 'amount' => '10', 'category' => 'float_top_up'])
            ->assertStatus(422)
            ->assertJsonPath('message', "Open a shift on this till first: cash is paid in or out of a shift's drawer.");
    }

    public function test_a_reprint_is_logged_and_marked_at_every_width(): void
    {
        $movement = app(CashMovementService::class)->create($this->till, $this->cashier, CashMovementType::PaidOut, 1_000, CashMovementCategory::Expense);
        $this->travel(20)->minutes();

        $url = $this->actingAs($this->cashier)
            ->postJson(route('pos.cash-movements.reprint', $movement), ['device' => $this->till->uuid])
            ->assertOk()
            ->json('slip_url');

        $entry = AuditLog::query()->where('event', 'cash_movement_reprinted')->sole();
        $this->assertSame((int) $this->cashier->getKey(), (int) $entry->user_id);
        $this->assertSameIgnoringKeyOrder(['number' => 'CM-000001', 'device_id' => $this->till->getKey(), 'till' => 'T1', 'source' => 'till'], $entry->new_values);

        $slip = $this->page($url);
        $this->assertStringStartsWith('*** REPRINT ***', trim($slip));
        $this->assertMatchesRegularExpression('/REPRINT 18\/10\/2026 10:20\n\s*by '.preg_quote($this->cashier->name, '/').'\s*$/', $slip);

        // Opening it again writes nothing more.
        $this->page($url);
        $this->assertSame(1, AuditLog::query()->where('event', 'cash_movement_reprinted')->count());

        $service = app(CashMovementService::class);
        foreach ([48 => 80, 32 => 58] as $columns => $paper) {
            $printed = $this->printed('cash', $service->slip($movement, $entry), $paper);
            $this->assertStringContainsString('*** REPRINT ***', $printed);
            $this->assertMatchesRegularExpression('/^Received by _{'.($columns - 12).'}$/m', $printed);
            $this->assertMatchesRegularExpression('/^AMOUNT LKR\s+10\.00$/m', $printed);

            foreach (explode("\n", $printed) as $line) {
                $this->assertLessThanOrEqual($columns, strlen($line), "{$columns} columns: {$line}");
            }
        }
    }

    public function test_only_the_reprinter_opens_a_reprint_and_only_whoever_may_read_the_shift_reprints(): void
    {
        $movement = app(CashMovementService::class)->create($this->till, $this->cashier, CashMovementType::PaidOut, 1_000, CashMovementCategory::Expense);
        $url = $this->actingAs($this->owner)
            ->postJson(route('pos.cash-movements.reprint', $movement), ['device' => $this->till->uuid])
            ->assertOk()->json('slip_url');

        $this->actingAs($this->cashier)->get($url)->assertNotFound();

        $other = $this->cashierAt();
        $this->actingAs($other)
            ->postJson(route('pos.cash-movements.reprint', $movement), ['device' => $this->till->uuid])
            ->assertForbidden();
        $this->actingAs($other)->get(route('pos.cash-movements.slip', $movement))->assertForbidden();
    }

    public function test_a_voided_slip_says_so_and_only_the_owner_voids(): void
    {
        $movement = app(CashMovementService::class)->create($this->till, $this->cashier, CashMovementType::PaidOut, 1_000, CashMovementCategory::Expense);

        $this->actingAs($this->cashier)
            ->postJson(route('pos.cash-movements.void', $movement), ['reason' => 'Mistake'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You are not allowed to void cash movements.');
        $this->actingAs($this->owner)
            ->postJson(route('pos.cash-movements.void', $movement), [])
            ->assertJsonValidationErrors('reason');
        $this->actingAs($this->owner)
            ->postJson(route('pos.cash-movements.void', $movement), ['reason' => 'Typed 1,000 for 10'])
            ->assertOk()
            ->assertJsonPath('movement.voided', true)
            ->assertJsonPath('movement.void_reason', 'Typed 1,000 for 10');

        $slip = $this->page(route('pos.cash-movements.slip', $movement));
        $this->assertStringContainsString('*** VOIDED ***', $slip);
        $this->assertStringContainsString('Reason: Typed 1,000 for 10', $slip);
        $this->assertSame(500_000, app(ShiftService::class)->expectedCash($this->shift));
    }

    public function test_the_page_shows_the_cash_button_flags(): void
    {
        $this->actingAs($this->cashier)->withCookie('pos_till', $this->till->uuid)->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('cashMovements.canMove', true)
                ->where('cashMovements.canPayDoctor', true)
                ->where('cashMovements.canVoid', false));

        Feature::for($this->shop)->deactivate('module:cash_movements');

        $this->actingAs($this->cashier)->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page->where('cashMovements', null));
        $this->actingAs($this->cashier)
            ->getJson(route('pos.cash-movements.index', ['device' => $this->till->uuid]))
            ->assertNotFound();
    }
}
