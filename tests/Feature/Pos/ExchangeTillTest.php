<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\Exchange;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PatientInput;
use App\Services\Data\PaymentInput;
use App\Services\ExchangeService;
use App\Services\OfflineSaleSyncService;
use App\Services\ShiftService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithReceipts;

/**
 * Return / Exchange at the till (docs/PHARMACY.md, branch 19): finding the
 * bill (invoice number, the patient's bills), its returnable lines, the
 * credit's preview, an exchange and a plain return from the till, the
 * EXCHANGE receipt at 48, 32 and 36 columns, reprints logged and marked,
 * Recent sales, and what the till refuses (offline sales, checkout).
 */
class ExchangeTillTest extends BatchTestCase
{
    use WorksWithCheckout, WorksWithReceipts;

    private Shift $shift;

    private Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockTheClinic();
        $this->shift = app(ShiftService::class)->open($this->till, $this->cashier, 500_000);
        // T1-000001: a box of 30 Panadol (540.00) and 10 Amoxil (350.00) for Nimal Perera, 34.
        $this->sale = app(CheckoutService::class)->checkout(new CheckoutData(
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

    private function line(int $index): int
    {
        return (int) $this->sale->items->values()[$index]->getKey();
    }

    /** @param array<string, mixed> $body */
    private function send(array $body, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->cashier)->postJson(route('pos.exchanges.store'), [
            'device' => $this->till->uuid,
            'uuid' => (string) Str::uuid(),
            'sale' => $this->sale->uuid,
            'reason' => 'side_effects',
            ...$body,
        ]);
    }

    /** 10 Amoxil to Quarantine (350.00), 10 Panadol tablets instead (200.00): 150.00 back. */
    private function exchangeAmoxil(?Doctor $doctor = null): TestResponse
    {
        return $this->send([
            'returns' => [['sale_item_id' => $this->line(1), 'quantity' => 10, 'to' => 'quarantine']],
            'lines' => [['variant_id' => $this->panadol->getKey(), 'quantity' => 10]],
            'doctor_id' => $doctor?->getKey(),
            'note' => 'Rash after two days',
        ]);
    }

    /** The lines of a receipt page as the browser prints them (large print, 36 columns). */
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

    public function test_the_dialog_offers_the_reasons_doctors_destinations_and_the_users_limits(): void
    {
        Doctor::query()->create(['name' => 'Dr. S. Perera']);

        $response = $this->actingAs($this->cashier)->getJson(route('pos.exchanges.index', ['device' => $this->till->uuid]))
            ->assertOk()
            ->assertJsonPath('exchanges', [])
            ->assertJsonPath('quarantine', 'Quarantine / Expired')
            ->assertJsonPath('here', 'General Pharmacy')
            ->assertJsonPath('cash_limit', 200_000)
            ->assertJsonPath('max_days', 14)
            ->assertJsonPath('can_late', false)
            ->assertJsonPath('can_no_receipt', false)
            ->assertJsonPath('doctors.0.name', 'Dr. S. Perera');
        $this->assertSame(['Not effective', 'Side effects', 'Wrong medicine', 'Other'], array_column($response->json('reasons'), 'label'));

        $this->actingAs($this->owner)->getJson(route('pos.exchanges.index', ['device' => $this->till->uuid]))
            ->assertJsonPath('cash_limit', null)
            ->assertJsonPath('can_late', true)
            ->assertJsonPath('can_no_receipt', true);
    }

    public function test_the_bill_is_found_by_invoice_number_or_the_patient_and_lists_what_can_come_back(): void
    {
        $this->actingAs($this->cashier)->getJson(route('pos.exchanges.bills', ['device' => $this->till->uuid, 'q' => 'T1-000001']))
            ->assertOk()
            ->assertJsonCount(1, 'bills')
            ->assertJsonPath('bills.0.uuid', $this->sale->uuid)
            ->assertJsonPath('bills.0.patient.name', 'Nimal Perera')
            ->assertJsonPath('bills.0.refusal', null);

        $patient = (string) $this->sale->patient?->uuid;
        $this->actingAs($this->cashier)->getJson(route('pos.exchanges.bills', ['device' => $this->till->uuid, 'patient' => $patient]))
            ->assertJsonPath('bills.0.invoice_no', 'T1-000001');

        $this->actingAs($this->cashier)->getJson(route('pos.exchanges.bill', ['sale' => $this->sale->uuid, 'device' => $this->till->uuid]))
            ->assertOk()
            ->assertJsonCount(2, 'bill.lines')
            ->assertJsonPath('bill.lines.0.unit_name', 'Box of 30')
            ->assertJsonPath('bill.lines.0.returnable', 1)
            ->assertJsonPath('bill.lines.0.returnable_base', 30)
            ->assertJsonPath('bill.lines.0.stock_note', null)
            ->assertJsonPath('bill.lines.1.returnable', 10)
            ->assertJsonPath('bill.lines.1.batches.0.batch_no', 'AX0921');

        // Two weeks and a day later the cashier is told to ask the owner.
        $this->travel(15)->days();
        $this->actingAs($this->cashier)->getJson(route('pos.exchanges.bills', ['device' => $this->till->uuid, 'q' => '000001']))
            ->assertJsonPath('bills.0.age_days', 15)
            ->assertJsonPath('bills.0.refusal', 'T1-000001 is 15 days old; the shop takes returns and exchanges at the till up to 14 days. Ask the owner.');
    }

    public function test_the_preview_shows_the_credit_without_recording_anything(): void
    {
        $this->actingAs($this->cashier)->postJson(route('pos.exchanges.preview'), [
            'device' => $this->till->uuid,
            'sale' => $this->sale->uuid,
            'returns' => [
                ['sale_item_id' => $this->line(0), 'quantity' => 5, 'in_base_units' => true, 'to' => 'stock'],
                ['sale_item_id' => $this->line(1), 'quantity' => 10, 'to' => 'quarantine'],
            ],
        ])->assertOk()->assertJsonPath('credit', 9_000 + 35_000);

        $this->actingAs($this->cashier)->postJson(route('pos.exchanges.preview'), [
            'device' => $this->till->uuid,
            'sale' => $this->sale->uuid,
            'returns' => [['sale_item_id' => $this->line(1), 'quantity' => 11, 'to' => 'quarantine']],
        ])->assertStatus(422)->assertJsonPath('message', 'Only 10 of '.$this->amoxil->sku.' can still be returned.');

        $this->assertSame(0, Exchange::query()->count());
    }

    public function test_an_exchange_from_the_till_records_it_and_prints_one_exchange_receipt(): void
    {
        $doctor = Doctor::query()->create(['name' => 'Dr. S. Perera']);
        $this->setShopSetting('receipt_show_patient_name', true);

        $response = $this->exchangeAmoxil($doctor)
            ->assertCreated()
            ->assertJsonPath('exchange.number', 'EX-000001')
            ->assertJsonPath('exchange.kind', 'exchange')
            ->assertJsonPath('exchange.original_invoice_no', 'T1-000001')
            ->assertJsonPath('exchange.new_invoice_no', 'T1-000002')
            ->assertJsonPath('exchange.credit', 35_000)
            ->assertJsonPath('exchange.cash_refunded', 15_000);

        $exchange = Exchange::query()->sole();
        $this->assertSame(route('pos.exchanges.receipt', $exchange), $response->json('receipt_url'));

        $receipt = $this->page($response->json('receipt_url'));
        $this->assertStringNotContainsString('REPRINT', $receipt);
        $this->assertStringContainsString('*** EXCHANGE ***', $receipt);
        $this->assertMatchesRegularExpression('/^No\.\s+EX-000001$/m', $receipt);
        $this->assertMatchesRegularExpression('/^Bill no\.\s+T1-000001$/m', $receipt);
        $this->assertMatchesRegularExpression('/^New bill\s+T1-000002$/m', $receipt);
        $this->assertMatchesRegularExpression('/^Patient\s+Nimal Perera$/m', $receipt);
        $this->assertMatchesRegularExpression('/^Doctor\s+Dr\. S\. Perera$/m', $receipt);
        $this->assertStringContainsString('RETURNED', $receipt);
        $this->assertStringContainsString('Amoxil Capsules 250 mg', $receipt);
        $this->assertStringContainsString('To Quarantine / Expired', $receipt);
        $this->assertMatchesRegularExpression('/^RETURN CREDIT\s+350\.00$/m', $receipt);
        $this->assertStringContainsString('NEW ITEMS', $receipt);
        $this->assertStringContainsString('Panadol Tablets 500 mg', $receipt);
        $this->assertMatchesRegularExpression('/^New items\s+200\.00$/m', $receipt);
        $this->assertMatchesRegularExpression('/^Return credit\s+-350\.00$/m', $receipt);
        $this->assertMatchesRegularExpression('/^REFUND LKR\s+150\.00$/m', $receipt);
        $this->assertMatchesRegularExpression('/^Exchange credit\s+200\.00$/m', $receipt);
        $this->assertMatchesRegularExpression('/^Cash refund\s+150\.00$/m', $receipt);
        $this->assertStringContainsString('Reason: Side effects', $receipt);
        $this->assertStringContainsString('Note: Rash after two days', $receipt);
        $this->assertMatchesRegularExpression("/^Patient's signature _+$/m", $receipt);

        // Without the setting, no patient's name.
        $this->setShopSetting('receipt_show_patient_name', false);
        $this->assertStringNotContainsString('Nimal Perera', $this->page($response->json('receipt_url')));
    }

    public function test_paying_more_prints_patient_pays_with_the_payments_and_change(): void
    {
        $response = $this->send([
            'returns' => [['sale_item_id' => $this->line(0), 'quantity' => 5, 'in_base_units' => true, 'to' => 'stock']],
            'lines' => [['variant_id' => $this->amoxil->getKey(), 'quantity' => 10, 'prescriber' => 'Dr. S. Perera']],
            'payments' => [['method' => 'cash', 'amount' => '300']],
            'reason' => 'wrong_medicine',
        ])->assertCreated()->assertJsonPath('exchange.patient_paid', 26_000)->assertJsonPath('exchange.change_due', 4_000);

        $receipt = $this->page($response->json('receipt_url'));
        $this->assertStringContainsString('Back on sale: General Pharmacy', $receipt);
        $this->assertMatchesRegularExpression('/^PATIENT PAYS LKR\s+260\.00$/m', $receipt);
        $this->assertMatchesRegularExpression('/^Exchange credit\s+90\.00$/m', $receipt);
        $this->assertMatchesRegularExpression('/^Cash\s+300\.00$/m', $receipt);
        $this->assertMatchesRegularExpression('/^Change\s+40\.00$/m', $receipt);
        $this->assertStringNotContainsString('Cash refund', $receipt);
        $this->assertStringNotContainsString('signature', $receipt);
    }

    public function test_a_plain_return_prints_a_return_receipt(): void
    {
        $response = $this->send([
            'returns' => [['sale_item_id' => $this->line(1), 'quantity' => 2, 'to' => 'quarantine']],
            'reason' => 'not_effective',
        ])->assertCreated()->assertJsonPath('exchange.kind', 'return')->assertJsonPath('exchange.cash_refunded', 7_000);

        $receipt = $this->page($response->json('receipt_url'));
        $this->assertStringContainsString('*** RETURN ***', $receipt);
        $this->assertStringNotContainsString('NEW ITEMS', $receipt);
        $this->assertMatchesRegularExpression('/^REFUND LKR\s+70\.00$/m', $receipt);
        $this->assertMatchesRegularExpression('/^Cash refund\s+70\.00$/m', $receipt);
    }

    public function test_the_receipt_fits_32_36_and_48_columns(): void
    {
        $this->setShopSetting('receipt_show_patient_name', true);
        $this->exchangeAmoxil(Doctor::query()->create(['name' => 'Dr. Sunethra Wickramasinghe-Jayawardena']));
        $exchanges = app(ExchangeService::class);
        $payload = $exchanges->receipt(Exchange::query()->sole());

        foreach ([[80, 48], [58, 32]] as [$paper, $columns]) {
            $printed = $this->printed('exchange', $payload, $paper);
            $this->assertStringContainsString('*** EXCHANGE ***', $printed);
            $this->assertStringContainsString('REFUND LKR', $printed);

            foreach (explode("\n", $printed) as $line) {
                $this->assertLessThanOrEqual($columns, strlen($line), "At {$columns}: {$line}");
            }
        }

        $large = $this->page(route('pos.exchanges.receipt', Exchange::query()->sole()));

        foreach (explode("\n", $large) as $line) {
            $this->assertLessThanOrEqual(36, strlen($line), "At 36: {$line}");
        }

        $this->assertStringContainsString('Wickramasinghe', $large);
    }

    public function test_a_reprint_is_logged_marked_and_opened_only_by_its_reprinter(): void
    {
        $this->exchangeAmoxil();
        $exchange = Exchange::query()->sole();

        $url = $this->actingAs($this->cashier)->postJson(route('pos.exchanges.reprint', $exchange), ['device' => $this->till->uuid])
            ->assertOk()->json('receipt_url');

        $entry = AuditLog::query()->where('event', ExchangeService::REPRINT_EVENT)->sole();
        $this->assertSame([(int) $this->cashier->getKey(), 'EX-000001', 'T1'], [(int) $entry->user_id, $entry->new_values['number'], $entry->new_values['till']]);

        $receipt = $this->page($url);
        $this->assertStringStartsWith('*** REPRINT ***', ltrim($receipt));
        $this->assertMatchesRegularExpression('/^\s*REPRINT 18\/10\/2026 10:00$/m', $receipt);
        $this->assertStringContainsString('by '.$this->cashier->name, $receipt);

        $this->actingAs($this->owner)->get($url)->assertNotFound();
    }

    public function test_the_till_lists_its_shifts_exchanges_and_recent_sales_mark_the_new_bill(): void
    {
        $this->exchangeAmoxil();

        $this->actingAs($this->cashier)->getJson(route('pos.exchanges.index', ['device' => $this->till->uuid]))
            ->assertJsonCount(1, 'exchanges')
            ->assertJsonPath('exchanges.0.number', 'EX-000001')
            ->assertJsonPath('exchanges.0.new_invoice_no', 'T1-000002')
            ->assertJsonPath('exchanges.0.reason', 'Side effects');

        $sales = $this->actingAs($this->cashier)->getJson(route('pos.recent-sales', ['device' => $this->till->uuid]))->assertOk()->json('sales');
        $this->assertSame(['T1-000002' => 'EX-000001', 'T1-000001' => null], array_column(array_map(fn (array $sale): array => ['no' => $sale['invoice_no'], 'ex' => $sale['exchange']['number'] ?? null], $sales), 'ex', 'no'));
    }

    public function test_the_till_refuses_exchange_credit_in_a_sale_and_an_offline_sync(): void
    {
        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), [
            'device' => $this->till->uuid,
            'uuid' => (string) Str::uuid(),
            'lines' => [['variant_id' => $this->panadol->getKey(), 'quantity' => 1]],
            'payments' => [['method' => 'exchange', 'amount' => '20']],
        ])->assertStatus(422)->assertJsonValidationErrors('payments.0.method');

        // An offline till never makes an exchange: a sale paid with it is rejected, not recorded.
        app(OfflineSaleSyncService::class)->reserveInvoiceBlock($this->till);
        $this->app['auth']->forgetGuards();
        $uuid = (string) Str::uuid();
        $this->withToken($this->till->issueSyncToken('Counter browser')->plainTextToken)
            ->postJson(route('pos.sync.sales'), ['device_time' => now()->toIso8601String(), 'sales' => [[
                'uuid' => $uuid,
                'invoice_no' => 'T1-000900',
                'sold_at' => now()->toIso8601String(),
                'cashier_id' => $this->cashier->getKey(),
                'lines' => [['variant_id' => $this->panadol->getKey(), 'quantity' => 1, 'unit_price' => 2_000]],
                'payments' => [['method' => 'exchange', 'amount' => 2_000]],
                'grand_total' => 2_000,
            ]]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected');

        $this->assertSame(1, Sale::query()->count());
    }

    public function test_it_needs_the_permission_and_the_pharmacy(): void
    {
        $nobody = $this->cashierAt();
        $nobody->syncRoles([]);
        $nobody->givePermissionTo('pos.checkout');

        $this->actingAs($nobody)->getJson(route('pos.exchanges.index', ['device' => $this->till->uuid]))->assertForbidden();
        $this->send(['returns' => [['sale_item_id' => $this->line(1), 'quantity' => 1, 'to' => 'quarantine']]], $nobody)->assertForbidden();

        $this->actingAs($this->cashier)->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page->where('exchanges', true));
    }
}
