<?php

namespace Tests\Feature\Pos;

use App\Enums\BillType;
use App\Enums\PaymentMethod;
use App\Filament\Pages\ShopSettings;
use App\Filament\Resources\Sales\Pages\ListSales;
use App\Filament\Resources\Sales\SaleResource;
use App\Models\Device;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use App\Services\CatalogSyncService;
use App\Services\CheckoutService;
use App\Services\Data\BatchInput;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\StockService;
use Filament\Facades\Filament;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * An OPD bill's medicines at list price (decided 2026-10-22): the till shows
 * the list value of the medicines dispensed (Σ list price × quantity of the
 * priced ones; never a cost) next to the Medicines amount, and warns without
 * blocking when the amount typed is below the shop's share of it
 * (opd_below_list_warning_percent, 100 by default, 0 = off). The sale records
 * the list value and that it was charged below it. Online the quote says so,
 * offline the till's own pricing (pricing.ts, run here under node) says the
 * same, and an offline sale records it on upload.
 */
class OpdListValueTest extends BatchTestCase
{
    use WorksWithCheckout;

    private Device $doc;

    private User $doctor;

    private ProductVariant $consult;

    private ProductVariant $syrup;

    private ProductVariant $tabs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doc = $this->docTill();
        $this->doctor = $this->cashierAt($this->doctors);
        $this->consult = $this->consultation(150_000);
        $this->syrup = $this->syrup();
        $this->tabs = $this->medicine();

        $this->receive($this->syrup, 'S1', '03/2027', 600, $this->doctors, 300);
        $this->receive($this->tabs, 'T1', '03/2027', 100, $this->doctors);
    }

    /** Consultation, 40 ml of syrup (list 20,000) and 15 tablets (list 30,000): list value 50,000. */
    private function payload(?string $medicines, array $extra = []): array
    {
        return [
            'device' => $this->doc->uuid,
            'bill_type' => 'opd',
            'medicines_amount' => $medicines,
            'lines' => [
                ['variant_id' => $this->consult->getKey(), 'quantity' => 1],
                ['variant_id' => $this->syrup->getKey(), 'quantity' => 40],
                ['variant_id' => $this->tabs->getKey(), 'quantity' => 15],
                ...$extra,
            ],
        ];
    }

    /** @return array<string, mixed> the quote's totals, as the till receives them */
    private function quote(?string $medicines, array $extra = []): array
    {
        return $this->actingAs($this->doctor)->postJson(route('pos.quote'), $this->payload($medicines, $extra))->assertOk()->json('totals');
    }

    private function percent(int $percent): void
    {
        $this->setShopSetting('opd_below_list_warning_percent', $percent);
    }

    private function sale(int $medicines, bool $offline = false): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->doc,
            cashier: $this->doctor,
            lines: [
                new CartLine($this->consult->getKey(), 1, ...($offline ? ['tillUnitPrice' => 150_000] : [])),
                new CartLine($this->syrup->getKey(), 40, ...($offline ? ['tillUnitPrice' => 500] : [])),
                new CartLine($this->tabs->getKey(), 15, ...($offline ? ['tillUnitPrice' => 2_000] : [])),
            ],
            payments: [new PaymentInput(PaymentMethod::Cash, 10_000_000)],
            billType: BillType::Opd,
            opdMedicinesAmount: $medicines,
            uuid: $offline ? (string) Str::uuid() : null,
            isOfflineSync: $offline,
            soldAt: $offline ? now()->subMinutes(5) : null,
        ));
    }

    public function test_the_quote_gives_the_list_value_and_warns_below_it_by_default(): void
    {
        $this->assertSame(100, $this->shop->setting('opd_below_list_warning_percent'));

        $below = $this->quote('300');
        $this->assertSame(50_000, $below['medicines_list_value']);
        $this->assertTrue($below['medicines_below_list']);

        // At the list value, above it, or not typed yet: no warning.
        $this->assertFalse($this->quote('500')['medicines_below_list']);
        $this->assertFalse($this->quote('650')['medicines_below_list']);
        $this->assertFalse($this->quote(null)['medicines_below_list']);
        $this->assertSame(50_000, $this->quote(null)['medicines_list_value']);
    }

    public function test_the_threshold_is_a_share_of_the_list_value_and_zero_turns_the_warning_off(): void
    {
        $this->percent(80);
        $this->assertFalse($this->quote('400')['medicines_below_list']);    // exactly 80%
        $this->assertTrue($this->quote('399.99')['medicines_below_list']);

        $this->percent(0);
        $this->assertFalse($this->quote('0')['medicines_below_list']);
        $this->assertSame(50_000, $this->quote('0')['medicines_list_value']);
    }

    public function test_a_standard_bill_has_no_list_value_or_warning(): void
    {
        $totals = $this->actingAs($this->doctor)->postJson(route('pos.quote'), [
            ...$this->payload(null), 'bill_type' => 'standard', 'lines' => array_slice($this->payload(null)['lines'], 1),
        ])->assertOk()->json('totals');

        $this->assertNull($totals['medicines_list_value']);
        $this->assertNull($totals['medicines_below_list']);
    }

    public function test_the_list_value_never_carries_a_cost(): void
    {
        // A medicine with no price on the list is weighed by its cost on the server: it adds nothing here.
        $secret = $this->medicine('Secret Tablets', []);
        $secret->units()->where('is_base', true)->firstOrFail()->prices()->delete();
        $this->receive($secret, 'X1', '03/2027', 100, $this->doctors, 1_337);
        $extra = [['variant_id' => $secret->getKey(), 'quantity' => 3]];

        $response = $this->actingAs($this->doctor)->postJson(route('pos.quote'), $this->payload('300', $extra))->assertOk();
        $this->assertSame(50_000, $response->json('totals.medicines_list_value'));

        // Nothing the till receives is a cost, or the cost itself.
        $keys = array_keys(Arr::dot($response->json()));
        $this->assertSame([], array_values(array_filter($keys, fn (string $key): bool => str_contains($key, 'cost'))));
        $this->assertStringNotContainsString('1337', $response->getContent());
        $this->assertStringNotContainsString('4011', $response->getContent());

        // The cost moving changes nothing.
        app(StockService::class)->stockIn($secret, $this->doctors, 100, $this->owner, 9_999, 'Dearer', batch: new BatchInput('X2', '03/2027', acceptNearExpiry: true));
        $this->assertSame(50_000, $this->quote('300', $extra)['medicines_list_value']);
    }

    public function test_the_sale_records_the_list_value_and_that_it_was_charged_below_it_without_being_stopped(): void
    {
        $below = $this->sale(30_000);
        $this->assertSame(50_000, $below->opd_medicines_list_value);
        $this->assertTrue($below->opd_below_list);
        $this->assertFalse($below->needs_review);
        $this->assertSame(180_000, $below->grand_total);

        $atList = $this->sale(50_000);
        $this->assertSame(50_000, $atList->opd_medicines_list_value);
        $this->assertFalse($atList->opd_below_list);

        $this->receive($this->tabs, 'G1', '03/2027', 10);
        $standard = app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->genTill(),
            cashier: $this->cashier,
            lines: [new CartLine($this->tabs->getKey(), 1)],
            payments: [new PaymentInput(PaymentMethod::Cash, 2_000)],
        ));
        $this->assertNull($standard->opd_medicines_list_value);
        $this->assertFalse($standard->opd_below_list);
    }

    public function test_an_opd_bill_rung_up_offline_records_it_when_it_uploads(): void
    {
        $sale = $this->sale(30_000, offline: true);

        $this->assertNotNull($sale->synced_at);
        $this->assertSame(50_000, $sale->opd_medicines_list_value);
        $this->assertTrue($sale->opd_below_list);
        $this->assertFalse($sale->needs_review, json_encode($sale->review_reasons) ?: '');
    }

    public function test_the_till_gets_the_threshold_online_and_in_its_offline_catalog(): void
    {
        $this->percent(80);

        $this->actingAs($this->doctor)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
            ->where('pharmacy.opdBelowListWarningPercent', 80));
        $this->assertSame(80, app(CatalogSyncService::class)->shopSettings($this->shop->fresh())['opdBelowListWarningPercent']);
    }

    public function test_the_tills_offline_pricing_gives_the_same_list_value_and_warning(): void
    {
        $version = Process::run(['node', '--version']);
        if (! $version->successful() || ! preg_match('/^v(\d+)\.(\d+)/', trim($version->output()), $m) || [(int) $m[1], (int) $m[2]] < [22, 6]) {
            $this->markTestSkipped('Needs node 22.6 or later (type stripping) to run the till\'s pricing.');
        }

        $catalog = app(CatalogSyncService::class)->changes($this->doc, null);
        $lines = fn (): array => array_map(fn (array $line): array => ['variantId' => $line['variant_id'], 'unitId' => null, 'quantity' => $line['quantity'], 'lineDiscount' => 0], $this->payload(null)['lines']);
        $cases = [];
        foreach ([[null, 100], ['300', 100], ['500', 100], ['400', 80], ['399.99', 80], ['0', 0]] as [$amount, $percent]) {
            $cases[] = ['lines' => $lines(), 'medicinesAmount' => $amount === null ? null : (int) round((float) $amount * 100), 'percent' => $percent, 'amount' => $amount];
        }

        $result = Process::path(base_path())
            ->input(json_encode(['catalog' => $catalog, 'cases' => $cases], JSON_THROW_ON_ERROR))
            ->run(['node', '--experimental-strip-types', '--no-warnings', 'tests/js/quote-offline.mjs']);
        $this->assertTrue($result->successful(), $result->errorOutput());
        $till = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);

        foreach ($cases as $n => $case) {
            $this->percent($case['percent']);
            $server = $this->quote($case['amount']);

            $this->assertSame($server['medicines_list_value'], $till[$n]['totals']['medicines_list_value'], "list value, case {$n}");
            $this->assertSame($server['medicines_below_list'], $till[$n]['totals']['medicines_below_list'], "warning, case {$n}");
            $this->assertSame([], array_values(array_filter($till[$n]['lineKeys'], fn (string $key): bool => str_contains($key, 'cost'))));
        }

        $this->assertSame([false, true, false, false, true, false], array_map(fn (array $one): bool => $one['totals']['medicines_below_list'], $till));
    }

    public function test_the_admin_sale_page_shows_the_list_value_and_the_flag_and_the_list_filters_by_it(): void
    {
        Filament::setCurrentPanel('admin');
        $below = $this->sale(30_000);
        $atList = $this->sale(50_000);
        $this->actingAs($this->owner);

        $this->get(SaleResource::getUrl('view', ['record' => $below]))->assertOk()
            ->assertSee('Medicines at list price')
            ->assertSee($this->shop->formatMoney(50_000).' · charged below list');
        $this->get(SaleResource::getUrl('view', ['record' => $atList]))->assertOk()
            ->assertDontSee('charged below list');

        Livewire::test(ListSales::class)
            ->filterTable('opd_below_list')
            ->assertCanSeeTableRecords([$below])
            ->assertCanNotSeeTableRecords([$atList]);
    }

    public function test_the_owner_sets_the_threshold_in_pharmacy_checkout_settings(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);

        Livewire::test(ShopSettings::class)
            ->assertFormSet(['opd_below_list_warning_percent' => 100])
            ->fillForm(['opd_below_list_warning_percent' => 150])
            ->call('save')
            ->assertHasFormErrors(['opd_below_list_warning_percent']);

        Livewire::test(ShopSettings::class)
            ->fillForm(['opd_below_list_warning_percent' => 90])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(90, $this->shop->fresh()->setting('opd_below_list_warning_percent'));
    }
}
