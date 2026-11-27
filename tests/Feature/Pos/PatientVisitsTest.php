<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Exceptions\PosException;
use App\Models\Location;
use App\Models\Sale;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\ExchangeData;
use App\Services\Data\PatientFilters;
use App\Services\Data\PatientInput;
use App\Services\Data\PaymentInput;
use App\Services\Data\ReturnLine;
use App\Services\ExchangeService;
use App\Services\PatientVisitsService;
use App\Services\ReturnService;
use App\Services\ShiftService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithReceipts;

/**
 * The visits behind the Patients list (docs/PHARMACY.md, branch 21): one
 * row per bill with a patient, not voided, with the medicines and other
 * items it gave (the consultation line left out), what came back on returns
 * and exchanges, and the amount net of refunds. Now is 2026-10-18 10:00 in
 * Colombo (BatchTestCase).
 */
class PatientVisitsTest extends BatchTestCase
{
    use WorksWithCheckout, WorksWithReceipts;

    private PatientVisitsService $visits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockTheClinic();             // Panadol 500 mg Tablets PN2611 x60; Amoxil 250 mg Capsules
        app(ShiftService::class)->open($this->till, $this->cashier, 500_000);
        $this->visits = app(PatientVisitsService::class);
    }

    /** 10 Amoxil, 6 Panadol tablets and a CBS lab test for Nimal Perera at T1 (General Pharmacy). */
    private function pharmacyBill(?string $patient = 'Nimal Perera'): Sale
    {
        $cbs = $this->openPriceItem('CBS', 50_000, 100_000);

        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: [
                new CartLine($this->amoxil->getKey(), 10, prescriber: 'Dr. S. Perera'),
                new CartLine($this->panadol->getKey(), 6),
                new CartLine($cbs->getKey(), 1),
            ],
            payments: [new PaymentInput(PaymentMethod::Cash, 1_000_000)],
            uuid: (string) Str::uuid(),
            patient: $patient === null ? null : new PatientInput((string) Str::uuid(), $patient, 408),
        ));
    }

    /** @return list<int> the ids of the visits the filters give */
    private function ids(PatientFilters $filters = new PatientFilters, ?string $search = null): array
    {
        return $this->visits->query($filters, $search)->orderBy('sales.id')->pluck('sales.id')->map(fn ($id): int => (int) $id)->all();
    }

    public function test_a_bill_gives_its_medicines_and_other_items_with_strength_quantity_and_unit(): void
    {
        $sale = $this->pharmacyBill();
        $visit = $this->visits->query(new PatientFilters)->sole();

        $this->assertSame((int) $sale->getKey(), (int) $visit->getKey());
        $this->assertSame([
            'medicines' => ['Amoxil Capsules 250 mg ×10 Capsule', 'Panadol Tablets 500 mg ×6 Tablet'],
            'other' => ['CBS ×1 pcs'],
        ], $this->visits->given($visit));
        $this->assertSame('Pharmacy', PatientVisitsService::kind($visit));
        $this->assertSame($sale->grand_total, PatientVisitsService::net($visit));
        $this->assertSame(['Nimal Perera', 'General Pharmacy', $this->cashier->name], [$visit->patient?->name, $visit->location?->name, $visit->cashier?->name]);
    }

    public function test_the_consultation_line_is_left_out_of_an_opd_bill(): void
    {
        $sale = $this->opdBill();
        $sale->update(['patient_id' => $this->pharmacyBill()->patient_id]);
        $visit = $this->visits->query(new PatientFilters(billType: PatientFilters::BILL_OPD))->sole();

        $this->assertSame('OPD', PatientVisitsService::kind($visit));
        $this->assertSame(['Paracetamol Syrup ×1 Bottle', 'Amoxil Capsules 250 mg ×10 Capsule'], $this->visits->given($visit)['medicines']);
        $this->assertSame([], $this->visits->given($visit)['other']);
        $this->assertNotContains('Consultation', array_column($visit->items->toArray(), 'description'));
    }

    public function test_a_strength_already_in_the_name_is_not_said_twice(): void
    {
        $tablets = $this->drug('Augmentin 625 mg Tablets', 625, 'mg');
        $this->receive($tablets, 'AG01', '03/2027', 20);
        $sale = $this->pharmacyBill();
        $sale->items()->where('product_variant_id', $this->panadol->getKey())->update(['product_variant_id' => $tablets->getKey(), 'description' => 'Augmentin 625 mg Tablets', 'strength' => '625 mg']);

        $this->assertContains('Augmentin 625 mg Tablets ×6 Tablet', $this->visits->given($this->visits->query(new PatientFilters)->sole())['medicines']);
    }

    public function test_returns_show_what_came_back_and_the_amount_is_net_of_refunds(): void
    {
        $sale = $this->pharmacyBill();
        $amoxil = $sale->items->firstWhere('product_variant_id', $this->amoxil->getKey());
        $cbs = $sale->items->firstWhere('description', 'CBS');
        $refund = app(ReturnService::class)->processLines($sale, [
            new ReturnLine((int) $amoxil->getKey(), 4),
            new ReturnLine((int) $cbs->getKey(), 1),
        ], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);

        $visit = $this->visits->query(new PatientFilters)->sole();
        $this->assertSame([
            'medicines' => ['Amoxil Capsules 250 mg ×6 Capsule (4 returned)', 'Panadol Tablets 500 mg ×6 Tablet'],
            'other' => ['CBS ×1 pcs returned'],
        ], $this->visits->given($visit));
        $this->assertSame($sale->grand_total - $refund->refund_total, PatientVisitsService::net($visit));
    }

    public function test_an_exchange_marks_its_new_bill_and_the_original_shows_what_came_back(): void
    {
        $sale = $this->pharmacyBill();
        $quarantine = Location::query()->where('code', 'QUAR')->firstOrFail();
        $exchange = app(ExchangeService::class)->create(new ExchangeData(
            till: $this->till,
            user: $this->cashier,
            uuid: (string) Str::uuid(),
            original: $sale,
            returnLines: [new ReturnLine((int) $sale->items->firstWhere('product_variant_id', $this->amoxil->getKey())->getKey(), 10, toLocationId: (int) $quarantine->getKey())],
            noReceiptLines: [],
            reason: ReturnReason::SideEffects,
            note: null,
            doctorId: null,
            newLines: [new CartLine($this->panadol->getKey(), 10)],
            payments: [],
        ));

        $visits = $this->visits->query(new PatientFilters)->orderBy('sales.id')->get();
        $this->assertCount(2, $visits);
        [$original, $new] = [$visits[0], $visits[1]];

        $this->assertSame('Pharmacy', PatientVisitsService::kind($original));
        $this->assertSame('Amoxil Capsules 250 mg ×10 Capsule returned', $this->visits->given($original)['medicines'][0]);
        $this->assertSame((int) $exchange->new_sale_id, (int) $new->getKey());
        $this->assertSame('Exchange EX-000001', PatientVisitsService::kind($new));
        $this->assertSame(['medicines' => ['Panadol Tablets 500 mg ×10 Tablet'], 'other' => []], $this->visits->given($new));
        $this->assertSame($new->grand_total, PatientVisitsService::net($new));
    }

    public function test_voided_bills_and_bills_without_a_patient_are_no_visit(): void
    {
        $kept = $this->pharmacyBill();
        $voided = $this->pharmacyBill('Rani Silva');
        app(CheckoutService::class)->void($voided, $this->owner, 'Rang up twice');
        $this->pharmacyBill(null);

        $this->assertSame([(int) $kept->getKey()], $this->ids());
        $this->assertSame([], $this->ids(search: 'rani'));
    }

    public function test_the_filters_and_the_search_narrow_the_visits(): void
    {
        $nimal = $this->pharmacyBill();
        $kamala = $this->pharmacyBill('Kamala Perera');
        $opd = $this->opdBill();
        $opd->update(['patient_id' => $kamala->patient_id]);

        $this->assertSame([(int) $nimal->getKey(), (int) $kamala->getKey(), (int) $opd->getKey()], $this->ids());
        $this->assertSame([(int) $opd->getKey()], $this->ids(new PatientFilters(billType: PatientFilters::BILL_OPD)));
        $this->assertSame([(int) $nimal->getKey(), (int) $kamala->getKey()], $this->ids(new PatientFilters(billType: PatientFilters::BILL_PHARMACY)));
        // Both: every matching bill of the patients with both kinds.
        $this->assertSame([(int) $kamala->getKey(), (int) $opd->getKey()], $this->ids(new PatientFilters(billType: PatientFilters::BILL_BOTH)));
        $this->assertSame([(int) $kamala->getKey(), (int) $opd->getKey()], $this->ids(new PatientFilters(minVisits: 2)));
        $this->assertSame([(int) $opd->getKey()], $this->ids(new PatientFilters(locationId: (int) $this->doctors->getKey())));
        $this->assertSame([(int) $nimal->getKey()], $this->ids(search: 'nimal'));
        $this->assertSame([], $this->ids(PatientFilters::fromTableState($this->shop->fresh(), ['last_visit' => ['range' => 'yesterday']])));
    }

    public function test_the_lines_of_a_page_are_read_in_one_query(): void
    {
        $this->receive($this->amoxil, 'AX1102', '02/2027', 50);

        foreach (range(1, 6) as $n) {
            $this->pharmacyBill('Patient '.$n);
        }

        DB::enableQueryLog();
        $page = $this->visits->query(new PatientFilters)->orderByDesc('sales.reported_at')->limit(25)->get();
        $page->each(fn (Sale $sale): array => $this->visits->given($sale) + [PatientVisitsService::kind($sale), $sale->patient?->name, $sale->location?->name, $sale->cashier?->name]);
        $queries = count(DB::getQueryLog());

        // The bills, then patients, locations, cashiers, exchanges and lines: one query each.
        $this->assertCount(6, $page);
        $this->assertLessThanOrEqual(6, $queries);
    }

    public function test_the_quick_view_lists_the_patients_bills_within_the_filters_newest_first(): void
    {
        $first = $this->pharmacyBill();
        $patient = $first->patient()->firstOrFail();
        $this->travel(1)->hours();
        $second = app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: [new CartLine($this->panadol->getKey(), 2)],
            payments: [new PaymentInput(PaymentMethod::Cash, 100_000)],
            patient: new PatientInput($patient->uuid),
        ));
        $this->pharmacyBill('Kamala Perera');

        $all = $this->visits->forPatient($patient, new PatientFilters);
        $this->assertSame([(int) $second->getKey(), (int) $first->getKey()], $all['bills']->modelKeys());
        $this->assertSame(0, $all['more']);

        $opd = $this->visits->forPatient($patient, new PatientFilters(billType: PatientFilters::BILL_OPD));
        $this->assertSame([], $opd['bills']->modelKeys());

        $yesterday = $this->visits->forPatient($patient, PatientFilters::fromTableState($this->shop->fresh(), ['last_visit' => ['range' => 'yesterday']]));
        $this->assertSame([], $yesterday['bills']->modelKeys());
    }

    public function test_the_quick_view_shows_the_latest_bills_and_says_how_many_more(): void
    {
        $bill = $this->pharmacyBill();

        // Copies of the bill (its lines are not needed to count them).
        foreach (range(1, PatientVisitsService::QUICK_VIEW_BILLS + 2) as $n) {
            $bill->replicate()->forceFill(['uuid' => (string) Str::uuid(), 'invoice_no' => 'X-'.$n])->saveQuietly();
        }

        $view = $this->visits->forPatient($bill->patient()->firstOrFail(), new PatientFilters);
        $this->assertCount(PatientVisitsService::QUICK_VIEW_BILLS, $view['bills']);
        $this->assertSame(3, $view['more']);
    }

    public function test_export_rows_give_every_line_in_full_and_the_amount_only_with_reports_view(): void
    {
        $sale = $this->pharmacyBill();
        $query = fn () => $this->visits->query(new PatientFilters)->orderByDesc('sales.reported_at')->orderByDesc('sales.id');

        $this->assertSame([[
            'time' => '18/10/2026 10:00',
            'patient' => 'Nimal Perera',
            'age' => '34 y',
            'kind' => 'Pharmacy',
            'location' => 'General Pharmacy',
            'invoice' => $sale->invoice_no,
            'amount' => $sale->grand_total,
            'medicines' => 'Amoxil Capsules 250 mg ×10 Capsule; Panadol Tablets 500 mg ×6 Tablet',
            'other' => 'CBS ×1 pcs',
            'cashier' => $this->cashier->name,
        ]], $this->visits->exportRows($query(), $this->owner));

        $exporter = User::factory()->create(['shop_id' => $this->shop->getKey()]);
        $exporter->givePermissionTo(['dispensing_register.view', 'patients.export']);
        $this->assertArrayNotHasKey('amount', $this->visits->exportRows($query(), $exporter)[0]);

        $viewer = User::factory()->create(['shop_id' => $this->shop->getKey()]);
        $viewer->givePermissionTo(['dispensing_register.view', 'reports.view']);
        $this->expectException(PosException::class);
        $this->visits->exportRows($query(), $viewer);
    }
}
