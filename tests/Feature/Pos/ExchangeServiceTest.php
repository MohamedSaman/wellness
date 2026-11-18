<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Enums\SaleStatus;
use App\Exceptions\PosException;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\Exchange;
use App\Models\Location;
use App\Models\NoReceiptReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Shift;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\ExchangeData;
use App\Services\Data\NoReceiptLine;
use App\Services\Data\PatientInput;
use App\Services\Data\PaymentInput;
use App\Services\Data\ReturnLine;
use App\Services\ExchangeService;
use App\Services\ShiftService;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithReceipts;

/**
 * Returns and exchanges at the till (docs/PHARMACY.md, branch 19): the
 * return credit pays the new bill (Exchange credit), the patient pays what
 * is left or gets the rest back in cash from this till's open shift, within
 * their paid out limit; the drawer moves by the difference only.
 */
class ExchangeServiceTest extends BatchTestCase
{
    use WorksWithCheckout, WorksWithReceipts;

    private Shift $shift;

    private Location $quarantine;

    private ExchangeService $exchanges;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockTheClinic();             // Panadol PN2611 03/2027 x60 (20.00 a tablet, 540.00 a box of 30); Amoxil 35.00 a capsule
        $this->shift = app(ShiftService::class)->open($this->till, $this->cashier, 500_000);
        $this->quarantine = Location::query()->where('code', 'QUAR')->firstOrFail();
        $this->exchanges = app(ExchangeService::class);
    }

    /** A box of 30 Panadol and 10 Amoxil for Nimal Perera, paid 890.00 in cash at T1 in the open shift. */
    private function bill(): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: [
                new CartLine($this->panadol->getKey(), 1, unitId: $this->unitNamed($this->panadol, 'Box of 30')),
                new CartLine($this->amoxil->getKey(), 10, prescriber: 'Dr. S. Perera'),
            ],
            payments: [new PaymentInput(PaymentMethod::Cash, 89_000)],
            patient: new PatientInput((string) Str::uuid(), 'Nimal Perera', 408),
        ));
    }

    private function line(Sale $sale, int $index): int
    {
        return (int) $sale->items->values()[$index]->getKey();
    }

    /**
     * @param  list<ReturnLine>  $lines
     * @param  list<CartLine>  $new
     * @param  list<PaymentInput>  $payments
     */
    private function exchange(?Sale $sale, array $lines, array $new = [], array $payments = [], ?User $user = null, ?string $uuid = null, ReturnReason $reason = ReturnReason::NotEffective, array $noReceipt = [], ?int $doctorId = null): Exchange
    {
        return $this->exchanges->create(new ExchangeData(
            till: $this->till,
            user: $user ?? $this->cashier,
            uuid: $uuid ?? (string) Str::uuid(),
            original: $sale,
            returnLines: $lines,
            noReceiptLines: $noReceipt,
            reason: $reason,
            note: $reason === ReturnReason::Other ? 'Patient asked' : null,
            doctorId: $doctorId,
            newLines: $new,
            payments: $payments,
        ));
    }

    private function toQuarantine(int $saleItemId, float $quantity, bool $inBaseUnits = false): ReturnLine
    {
        return new ReturnLine($saleItemId, $quantity, $inBaseUnits, toLocationId: (int) $this->quarantine->getKey());
    }

    private function backOnSale(int $saleItemId, float $quantity, bool $inBaseUnits = false): ReturnLine
    {
        return new ReturnLine($saleItemId, $quantity, $inBaseUnits, toLocationId: (int) $this->general->getKey());
    }

    private function onHand(Location $at, ?int $variantId = null): float
    {
        return (float) StockLevel::query()->where('product_variant_id', $variantId ?? $this->amoxil->getKey())->where('location_id', $at->getKey())->value('quantity');
    }

    private function refused(callable $attempt, string $message): void
    {
        try {
            $attempt();
            $this->fail("Expected: {$message}");
        } catch (PosException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_the_new_item_costs_less_so_the_rest_of_the_credit_is_refunded_in_cash(): void
    {
        $sale = $this->bill();
        $doctor = Doctor::query()->create(['name' => 'Dr. S. Perera']);

        // 10 Amoxil back (350.00, side effects), 10 Panadol tablets instead (200.00): refund 150.00.
        $exchange = $this->exchange($sale, [$this->toQuarantine($this->line($sale, 1), 10)], [new CartLine($this->panadol->getKey(), 10)], reason: ReturnReason::SideEffects, doctorId: (int) $doctor->getKey());

        $this->assertSame('EX-000001', $exchange->number);
        $this->assertSame([35_000, 20_000, 20_000, 0, 15_000], [$exchange->credit_total, $exchange->credit_applied, $exchange->new_total, $exchange->patient_paid, $exchange->cash_refunded]);
        $this->assertSame(ReturnReason::SideEffects, $exchange->reason);
        $this->assertSame((int) $doctor->getKey(), (int) $exchange->doctor_id);

        // The return: linked to the bill, credit recorded, the rest in cash from this shift.
        $return = SaleReturn::query()->sole();
        $this->assertSame([35_000, 20_000, 15_000], [$return->refund_total, $return->exchange_credit, $return->cashRefund()]);
        $this->assertSame((int) $this->shift->getKey(), (int) $return->shift_id);
        $this->assertSame(SaleStatus::PartiallyReturned, $sale->fresh()?->status);
        $this->assertSame(35_000, $sale->fresh()?->refunded_total);

        // The new bill: the same patient, paid with Exchange credit only.
        $new = $exchange->newSale;
        $this->assertNotNull($new);
        $this->assertSame([(int) $sale->patient_id, 20_000, 0], [(int) $new->patient_id, $new->grand_total, $new->change_due]);
        $this->assertSame([['exchange', 20_000]], $new->payments->map(fn ($p): array => [$p->method->value, $p->amount])->all());
        $this->assertSame((int) $sale->patient_id, (int) $exchange->patient_id);

        // Stock: the capsules to Quarantine (from the batches they were sold from), the tablets sold.
        $this->assertSame(10.0, $this->onHand($this->quarantine));
        $this->assertSame(55.0 - 10, $this->onHand($this->general));
        $this->assertSame(60.0 - 30 - 10, $this->onHand($this->general, (int) $this->panadol->getKey()));
        $this->assertSame((int) $this->quarantine->getKey(), (int) $return->items()->sole()->location_id);
        $this->assertLedgerMatches();

        // The drawer moves by the difference only: 5,000.00 + 890.00 − 150.00.
        $shifts = app(ShiftService::class);
        $this->assertSame(500_000 + 89_000 - 15_000, $shifts->expectedCash($this->shift));
        $summary = $shifts->summary($this->shift);
        $this->assertSame($shifts->expectedCash($this->shift), $summary['cash']['expected']);
        $this->assertSame(15_000, $summary['cash']['refunds']);
        $this->assertSame(['cash' => 89_000, 'exchange' => 20_000], array_column($summary['payments'], 'amount', 'method'));
        $this->assertSame('Exchange credit', collect($summary['payments'])->firstWhere('method', 'exchange')['label']);
        $this->assertSame([89_000 + 20_000, 35_000, 89_000 + 20_000 - 35_000], [$summary['gross'], $summary['refunds'], $summary['net']]);

        $entry = AuditLog::query()->where('event', ExchangeService::EXCHANGED_EVENT)->sole();
        $this->assertSame(['EX-000001', 'exchange', $sale->invoice_no, $new->invoice_no, 15_000], [$entry->new_values['number'], $entry->new_values['kind'], $entry->new_values['original_invoice_no'], $entry->new_values['new_invoice_no'], $entry->new_values['cash_refunded']]);
    }

    public function test_the_new_item_costs_more_so_the_patient_pays_the_difference(): void
    {
        $sale = $this->bill();

        // 5 tablets back from the box (90.00), 10 Amoxil instead (350.00): the patient pays 260.00 (300.00 cash, 40.00 change).
        $exchange = $this->exchange(
            $sale,
            [$this->backOnSale($this->line($sale, 0), 5, inBaseUnits: true)],
            [new CartLine($this->amoxil->getKey(), 10, prescriber: 'Dr. S. Perera')],
            [new PaymentInput(PaymentMethod::Cash, 30_000)],
            reason: ReturnReason::WrongMedicine,
        );

        $this->assertSame([9_000, 9_000, 35_000, 26_000, 0], [$exchange->credit_total, $exchange->credit_applied, $exchange->new_total, $exchange->patient_paid, $exchange->cash_refunded]);
        $new = $exchange->newSale;
        $this->assertNotNull($new);
        $this->assertSame([['exchange', 9_000], ['cash', 30_000]], $new->payments->map(fn ($p): array => [$p->method->value, $p->amount])->all());
        $this->assertSame(4_000, $new->change_due);
        $this->assertSame(0, SaleReturn::query()->sole()->cashRefund());

        // Back on sale into the batch it came from at General Pharmacy.
        $this->assertSame(60.0 - 30 + 5, (float) $this->batch($this->panadol, 'PN2611')->quantity);
        $this->assertLedgerMatches();

        // The drawer: + 260.00 only.
        $this->assertSame(500_000 + 89_000 + 26_000, app(ShiftService::class)->expectedCash($this->shift));
    }

    public function test_paying_more_than_the_credit_needs_the_patients_payment(): void
    {
        $sale = $this->bill();

        $this->refused(fn () => $this->exchange($sale, [$this->toQuarantine($this->line($sale, 0), 5, true)], [new CartLine($this->amoxil->getKey(), 10, prescriber: 'Dr. S. Perera')]), 'Payment is short by');
        $this->assertSame([0, 0], [Exchange::query()->count(), SaleReturn::query()->count()]);
        $this->assertSame(0.0, $this->onHand($this->quarantine, (int) $this->panadol->getKey()));
    }

    public function test_a_plain_return_at_the_till_refunds_the_whole_credit_in_cash(): void
    {
        $sale = $this->bill();

        $exchange = $this->exchange($sale, [$this->backOnSale($this->line($sale, 0), 10, inBaseUnits: true)], reason: ReturnReason::Other);

        $this->assertTrue($exchange->isPlainReturn());
        $this->assertSame([18_000, 0, 0, 0, 18_000], [$exchange->credit_total, $exchange->credit_applied, $exchange->new_total, $exchange->patient_paid, $exchange->cash_refunded]);
        $this->assertSame('Patient asked', $exchange->note);
        $this->assertSame(1, Sale::query()->count());
        $this->assertSame(60.0 - 30 + 10, (float) $this->batch($this->panadol, 'PN2611')->quantity);
        $this->assertSame(500_000 + 89_000 - 18_000, app(ShiftService::class)->expectedCash($this->shift));
        $this->assertSame((int) $sale->patient_id, (int) $exchange->patient_id);
    }

    public function test_quantities_are_limited_to_what_can_still_be_returned(): void
    {
        $sale = $this->bill();

        $this->refused(fn () => $this->exchange($sale, [$this->toQuarantine($this->line($sale, 1), 11)]), 'Only 10');

        $this->exchange($sale, [$this->toQuarantine($this->line($sale, 1), 6)]);
        $this->refused(fn () => $this->exchange($sale, [$this->toQuarantine($this->line($sale, 1), 5)]), 'Only 4');
        $this->exchange($sale, [$this->toQuarantine($this->line($sale, 1), 4)]);

        // Everything of the line back: exactly what was paid for it, 350.00.
        $this->assertSame(35_000, (int) SaleReturn::query()->sum('refund_total'));
        $this->assertSame(['EX-000001', 'EX-000002'], Exchange::query()->orderBy('id')->pluck('number')->all());
    }

    public function test_cash_back_is_capped_by_the_users_paid_out_limit_but_paying_more_is_not(): void
    {
        $this->setShopSetting('cash_movement_limits', ['cashier' => ['paid_out' => 10_000, 'doctor_payment' => 2_500_000]]);
        $sale = $this->bill();

        // A box back (540.00) is above the cashier's 100.00.
        $this->refused(fn () => $this->exchange($sale, [$this->toQuarantine($this->line($sale, 0), 1)]), 'Refunding 540.00 in cash is above your limit of 100.00. Ask the owner.');
        $this->assertSame([0, 0, 0.0], [Exchange::query()->count(), SaleReturn::query()->count(), $this->onHand($this->quarantine, (int) $this->panadol->getKey())]);

        // Exchanged for Amoxil worth 350.00, 190.00 back is still above it; 10 tablets (180.00) for 3 capsules (105.00) leave 75.00.
        $this->refused(fn () => $this->exchange($sale, [$this->toQuarantine($this->line($sale, 0), 1)], [new CartLine($this->amoxil->getKey(), 10, prescriber: 'Dr. S. Perera')]), 'Refunding 190.00 in cash');
        $exchange = $this->exchange($sale, [$this->toQuarantine($this->line($sale, 0), 10, true)], [new CartLine($this->amoxil->getKey(), 3, prescriber: 'Dr. S. Perera')]);
        $this->assertSame(18_000 - 10_500, $exchange->cash_refunded);

        // The owner has no limit; paying more is never capped.
        $this->assertSame(36_000, $this->exchange($sale, [$this->toQuarantine($this->line($sale, 0), 20, true)], user: $this->owner)->cash_refunded);
        $more = $this->exchange($sale, [$this->toQuarantine($this->line($sale, 1), 1)], [new CartLine($this->amoxil->getKey(), 40, prescriber: 'Dr. S. Perera')], [new PaymentInput(PaymentMethod::Cash, 200_000)]);
        $this->assertSame(140_000 - 3_500, $more->patient_paid);
    }

    public function test_a_bill_older_than_the_time_limit_needs_the_owner(): void
    {
        $sale = $this->bill();
        $this->travel(15)->days();
        $this->shift->update(['opened_at' => now()]);

        $this->refused(fn () => $this->exchange($sale, [$this->toQuarantine($this->line($sale, 1), 1)]), "{$sale->invoice_no} is 15 days old; the shop takes returns and exchanges at the till up to 14 days. Ask the owner.");
        $this->assertSame(1, $this->exchange($sale, [$this->toQuarantine($this->line($sale, 1), 1)], user: $this->owner)->id);

        $this->setShopSetting('exchange_max_days', 30);
        $this->exchange($sale, [$this->toQuarantine($this->line($sale, 1), 1)]);
        $this->setShopSetting('exchange_max_days', 0);
        $this->travel(400)->days();
        $this->exchange($sale, [$this->toQuarantine($this->line($sale, 1), 1)]);
        $this->assertSame(3, Exchange::query()->count());
    }

    public function test_the_time_limit_counts_shop_days(): void
    {
        $sale = $this->bill(); // 2026-10-18 10:00 in Colombo
        $this->travelTo(now()->addDays(14)->setTimezone('Asia/Colombo')->setTime(23, 59)->utc());

        $this->assertSame(14, $this->exchanges->ageInDays($sale));
        $this->assertNull($this->exchanges->refusal($sale, $this->cashier));
        $this->travel(2)->minutes();
        $this->assertSame(15, $this->exchanges->ageInDays($sale));
        $this->assertNotNull($this->exchanges->refusal($sale, $this->cashier));
    }

    public function test_it_needs_the_permission_an_open_shift_and_a_standard_bill(): void
    {
        $sale = $this->bill();

        Role::findByName('cashier')->revokePermissionTo('pos.exchange');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->refused(fn () => $this->exchange($sale, [$this->toQuarantine($this->line($sale, 1), 1)], user: $this->cashier->fresh()), 'You are not allowed to return or exchange items at the till.');

        app(ShiftService::class)->close($this->shift, $this->owner, 589_000);
        $this->refused(fn () => $this->exchange($sale, [$this->toQuarantine($this->line($sale, 1), 1)], user: $this->owner), 'Open a shift on this till first');

        $this->refused(fn () => $this->exchange($this->opdBill(), [], user: $this->owner), 'Tick what came back.');
        $opd = Sale::query()->where('bill_type', 'opd')->firstOrFail();
        $this->refused(fn () => $this->exchange($opd, [$this->toQuarantine((int) $opd->items->first()?->getKey(), 1)], user: $this->owner), 'is an OPD bill');
        $this->assertSame(0, Exchange::query()->count());
    }

    public function test_the_reason_must_be_a_till_reason_and_other_needs_a_note(): void
    {
        $sale = $this->bill();

        $this->refused(fn () => $this->exchange($sale, [$this->toQuarantine($this->line($sale, 1), 1)], reason: ReturnReason::ChangedMind), 'Choose why the items came back.');
        $this->refused(fn () => $this->exchanges->create(new ExchangeData($this->till, $this->cashier, (string) Str::uuid(), $sale, [$this->toQuarantine($this->line($sale, 1), 1)], [], ReturnReason::Other, '  ', null)), 'Say why in the note');
        $this->refused(fn () => $this->exchange($sale, [new ReturnLine($this->line($sale, 1), 1)]), 'Send each item to Quarantine or back on sale here.');
        $this->refused(fn () => $this->exchange($sale, [new ReturnLine($this->line($sale, 1), 1, toLocationId: (int) $this->doctors->getKey())]), 'Send each item to Quarantine or back on sale here.');
    }

    public function test_back_on_sale_needs_a_known_batch_that_has_not_expired(): void
    {
        $sale = $this->bill();
        // The first 5 capsules came from AX0921, which expires 30/11/2026.
        $this->travelTo(now()->setDate(2026, 11, 30));
        $this->shift->update(['opened_at' => now()]);
        $this->setShopSetting('exchange_max_days', 0);

        $this->refused(fn () => $this->exchange($sale, [$this->backOnSale($this->line($sale, 1), 10)]), 'Batch AX0921 of');
        $this->assertSame(0, SaleReturn::query()->count());

        // The 5 from the later batch go back on sale (latest expiry first); the expired ones to Quarantine.
        $this->exchange($sale, [$this->backOnSale($this->line($sale, 1), 5)]);
        $this->assertSame(50.0 - 5 + 5, (float) $this->batch($this->amoxil, 'AX1102')->quantity);
        $this->exchange($sale, [$this->toQuarantine($this->line($sale, 1), 5)]);
        $this->assertSame(5.0, (float) $this->batch($this->amoxil, 'AX0921', $this->quarantine)->quantity);
        $this->assertLedgerMatches();
    }

    public function test_stock_of_an_unknown_batch_goes_to_quarantine_only(): void
    {
        // Sold offline with nothing on hand: the unallocated batch.
        $syrup = $this->medicine('Cough Syrup');
        $sale = $this->sell([new CartLine($syrup->getKey(), 4)], offline: true);

        $this->refused(fn () => $this->exchange($sale, [$this->backOnSale($this->line($sale, 0), 4)]), 'is not known: send it to Quarantine');
        $this->exchange($sale, [$this->toQuarantine($this->line($sale, 0), 4)]);
        $this->assertSame(4.0, (float) $this->unallocated($syrup, $this->quarantine)?->quantity);
        $this->assertLedgerMatches();
    }

    public function test_a_retry_with_the_same_uuid_returns_the_same_exchange(): void
    {
        $sale = $this->bill();
        $uuid = (string) Str::uuid();
        $send = fn (): Exchange => $this->exchange($sale, [$this->toQuarantine($this->line($sale, 1), 10)], [new CartLine($this->panadol->getKey(), 10)], uuid: $uuid);

        $first = $send();
        $again = $send();

        $this->assertSame($first->getKey(), $again->getKey());
        $this->assertSame([1, 1, 2], [Exchange::query()->count(), SaleReturn::query()->count(), Sale::query()->count()]);
        $this->assertSame(10.0, $this->onHand($this->quarantine));

        // Another till may not reuse it.
        $this->refused(fn () => $this->exchanges->create(new ExchangeData($this->docTill(false), $this->cashier, $uuid, $sale, [$this->toQuarantine($this->line($sale, 1), 1)], [], ReturnReason::NotEffective, null, null)), 'belongs to another till');
    }

    public function test_exchange_credit_is_refused_outside_an_exchange(): void
    {
        $this->refused(fn () => app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: [new CartLine($this->panadol->getKey(), 1)],
            payments: [new PaymentInput(PaymentMethod::Exchange, 2_000)],
        )), 'Exchange credit is only taken in an exchange');

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_without_a_bill_only_the_owner_gives_credit(): void
    {
        $this->refused(fn () => $this->exchange(null, [], noReceipt: [new NoReceiptLine($this->panadol->getKey(), 5)]), 'Only the owner takes items back without their bill.');

        // 5 tablets (100.00) to Quarantine, 2 Amoxil (70.00) instead: 30.00 back.
        $exchange = $this->exchange(null, [], [new CartLine($this->amoxil->getKey(), 2, prescriber: 'Dr. S. Perera')], user: $this->owner, noReceipt: [new NoReceiptLine($this->panadol->getKey(), 5)]);

        $return = NoReceiptReturn::query()->sole();
        $this->assertSame([10_000, 7_000], [$return->refund_total, $return->exchange_credit]);
        $this->assertSame((int) $return->getKey(), (int) $exchange->no_receipt_return_id);
        $this->assertNull($exchange->original_sale_id);
        $this->assertSame([10_000, 7_000, 3_000], [$exchange->credit_total, $exchange->credit_applied, $exchange->cash_refunded]);
        $this->assertSame(500_000 - 3_000, app(ShiftService::class)->expectedCash($this->shift));
        $this->assertSame(5.0, (float) $this->unallocated($this->panadol, $this->quarantine)?->quantity);
    }
}
