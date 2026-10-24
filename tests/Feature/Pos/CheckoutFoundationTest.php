<?php

namespace Tests\Feature\Pos;

use App\Enums\BatchStatus;
use App\Enums\BillType;
use App\Enums\DrugSchedule;
use App\Exceptions\PosException;
use App\Models\Device;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemBatch;
use App\Models\Shop;
use App\Models\StockBatch;
use App\Services\BatchService;
use App\Services\Data\CartLine;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * The foundation of pharmacy checkout (docs/PHARMACY.md §9, branch 8): the
 * new sale and line columns, settings, permissions, and the sellable-batch
 * rule shared by FEFO, the till's preview and search.
 */
class CheckoutFoundationTest extends BatchTestCase
{
    use WorksWithCheckout;

    public function test_a_sale_is_a_standard_bill_reported_at_its_sale_time(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '11/2026', 20);

        $online = $this->sell([new CartLine($panadol->getKey(), 2)])->fresh();
        $offline = $this->sell([new CartLine($panadol->getKey(), 1)], offline: true, soldAt: now()->subHours(2))->fresh();

        foreach ([$online, $offline] as $sale) {
            $this->assertInstanceOf(Sale::class, $sale);
            $this->assertSame(BillType::Standard, $sale->bill_type);
            $this->assertSame($sale->sold_at->toDateTimeString(), $sale->reported_at?->toDateTimeString());
            $this->assertNull($sale->opd_medicines_amount);
            $this->assertNull($sale->clock_offset_seconds);

            $line = $sale->items->sole();
            $this->assertFalse($line->is_opd_medicine);
            $this->assertNull($line->prescription_id);
            $this->assertNull($line->prescriber);
            $this->assertNull($line->prescription_ref);
        }

        $this->assertSame(now()->subHours(2)->toDateTimeString(), $offline->reported_at?->toDateTimeString());
    }

    public function test_the_new_columns_cast_to_their_types(): void
    {
        $this->assertSame(['standard', 'opd'], array_map(fn (BillType $type): string => $type->value, BillType::cases()));
        $this->assertSame('Bill', BillType::Standard->label());
        $this->assertSame('OPD bill', BillType::Opd->label());

        $sale = (new Sale)->forceFill([
            'bill_type' => 'opd', 'opd_medicines_amount' => '120000', 'clock_offset_seconds' => '-930', 'reported_at' => '2026-10-18 10:00:00',
        ]);
        $this->assertSame(BillType::Opd, $sale->bill_type);
        $this->assertSame(120_000, $sale->opd_medicines_amount);
        $this->assertSame(-930, $sale->clock_offset_seconds);
        $this->assertSame('2026-10-18 10:00:00', $sale->reported_at?->toDateTimeString());
        $this->assertSame(BillType::Standard, (new Sale)->bill_type);

        $line = (new SaleItem)->forceFill(['list_price' => '15000', 'is_opd_medicine' => 1, 'drug_schedule' => 'controlled', 'prescription_id' => '7']);
        $this->assertSame(15_000, $line->list_price);
        $this->assertTrue($line->is_opd_medicine);
        $this->assertSame(DrugSchedule::Controlled, $line->drug_schedule);
        $this->assertSame(7, $line->prescription_id);
        $this->assertFalse((new SaleItem)->is_opd_medicine);

        $this->assertFalse($this->till->fresh()?->opd_billing);
        $this->assertFalse((new Device)->opd_billing);
        $this->assertTrue($this->docTill()->fresh()?->opd_billing);
    }

    public function test_only_a_product_without_stock_allows_a_typed_price(): void
    {
        $consultation = $this->consultation();
        $panadol = $this->medicine();

        $this->assertTrue($consultation->product->allow_price_override);
        $this->assertTrue($consultation->product->allowsPriceOverride());
        $this->assertSame($consultation->getKey(), $this->shop->setting('opd_consultation_variant_id'));

        // A product that holds stock never takes a typed price, whatever its flag says.
        $panadol->product->update(['allow_price_override' => true]);
        $this->assertFalse($panadol->product->fresh()?->allowsPriceOverride());
        $this->assertFalse((new Product)->allow_price_override);
    }

    public function test_the_shop_defaults_and_the_pharmacy_profile_settings(): void
    {
        $defaults = config('pos.shop_defaults');
        $this->assertSame('prompt', $defaults['rx_prescriber']);
        $this->assertNull($defaults['default_prescriber']);
        $this->assertFalse($defaults['controlled_register']);
        $this->assertNull($defaults['opd_consultation_variant_id']);
        $this->assertSame('names', $defaults['opd_receipt_medicines']);
        $this->assertFalse($defaults['bank_transfer_reference_required']);
        $this->assertSame(10, $defaults['till_clock_tolerance_minutes']);

        // A shop that never set them reads the defaults.
        $plain = new Shop(['settings' => []]);
        $this->assertSame('prompt', $plain->setting('rx_prescriber'));
        $this->assertFalse($plain->setting('bank_transfer_reference_required'));

        // The clinic started from the pharmacy profile.
        $this->assertSame('required', $this->shop->setting('rx_prescriber'));
        $this->assertTrue($this->shop->setting('bank_transfer_reference_required'));
        $this->assertNull($this->shop->setting('default_prescriber'));
        $this->assertFalse($this->shop->setting('controlled_register'));
        $this->assertSame('names', $this->shop->setting('opd_receipt_medicines'));
        $this->assertSame(10, $this->shop->setting('till_clock_tolerance_minutes'));

        $this->setShopSetting('default_prescriber', 'Dr. S. Perera');
        $this->assertSame('Dr. S. Perera', $this->shop->fresh()?->setting('default_prescriber'));
    }

    public function test_cashiers_may_type_service_prices_and_ring_up_opd_bills_after_the_sync(): void
    {
        $this->assertArrayHasKey('pos.price_override', config('pos.permissions'));
        $this->assertArrayHasKey('pos.opd_bill', config('pos.permissions'));

        // As on a live install from before this branch: the role lacks them until the migration runs.
        Role::findByName('cashier')->revokePermissionTo(['pos.price_override', 'pos.opd_bill']);
        $this->assertFalse($this->cashier->fresh()?->can('pos.opd_bill'));

        (require database_path('migrations/2026_10_23_000003_sync_permissions_for_pharmacy_checkout.php'))->up();

        foreach ([$this->cashier->fresh(), $this->owner->fresh()] as $user) {
            $this->assertNotNull($user);
            $this->assertTrue($user->can('pos.price_override'));
            $this->assertTrue($user->can('pos.opd_bill'));
        }
    }

    public function test_min_days_to_expiry_is_the_medicines_own_else_the_shops(): void
    {
        $insulin = $this->medicine('Insulin', [], minDaysToExpiry: 30);
        $panadol = $this->medicine();
        $batches = app(BatchService::class);

        $this->assertSame(30, $batches->minDaysToExpiry($insulin));
        $this->assertSame(30, $batches->minDaysToExpiry($insulin->product));
        $this->assertSame(0, $batches->minDaysToExpiry($panadol));

        $this->setShopSetting('min_days_to_expiry_for_sale', 7);
        $this->assertSame(7, $batches->minDaysToExpiry($panadol));
        $this->assertSame(30, $batches->minDaysToExpiry($insulin));
    }

    public function test_the_sellable_batches_are_the_ones_fefo_takes_in_its_order(): void
    {
        $panadol = $this->medicine();
        $this->foundExpired($panadol, 'GONE', '09/2026', 5);
        $this->receive($panadol, 'QUAR', '12/2026', 5)->update(['status' => BatchStatus::Quarantined]);
        $near = $this->receive($panadol, 'NEAR', '2026-10-31', 2);
        $a = $this->receive($panadol, 'A', '03/2027', 4);
        $this->travel(1)->minutes();
        $b = $this->receive($panadol, 'B', '03/2027', 6);
        $c = $this->receive($panadol, 'C', '01/2027', 3);
        $this->receive($panadol, 'ELSEWHERE', '11/2026', 9, $this->doctors);

        $this->assertSellableMatchesFefo($panadol, [$near, $c, $a, $b], minDays: 0);
    }

    public function test_the_minimum_days_to_expiry_hold_a_batch_back_in_both(): void
    {
        $insulin = $this->medicine('Insulin', [], minDaysToExpiry: 30);
        $this->receive($insulin, 'SOON', '2026-11-10', 2); // 23 days from 18 October
        $edge = $this->receive($insulin, 'EDGE', '2026-11-17', 3); // exactly 30 days: still sellable
        $later = $this->receive($insulin, 'LATER', '06/2027', 4);

        $this->assertSellableMatchesFefo($insulin, [$edge, $later], minDays: 30);
    }

    public function test_the_unallocated_batch_is_never_sellable(): void
    {
        $panadol = $this->medicine();
        $good = $this->receive($panadol, 'A', '03/2027', 2);
        $this->sell([new CartLine($panadol->getKey(), 5)], offline: true);
        $unallocated = $this->unallocated($panadol);
        $this->assertNotNull($unallocated);

        // Even holding stock (it never does for long: the next receipt settles it).
        StockBatch::query()->whereKey($unallocated->getKey())->update(['quantity' => 4]);
        $this->receive($panadol, 'B', '04/2027', 1);

        $ids = StockBatch::query()->where('product_variant_id', $panadol->getKey())->sellableOn(now(), 0)->pluck('id')->all();

        $this->assertNotContains($unallocated->getKey(), $ids);
        $this->assertNotContains($good->getKey(), $ids); // sold out
    }

    public function test_a_later_day_drops_the_batches_expired_by_then(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'NOV', '11/2026', 2);
        $jan = $this->receive($panadol, 'JAN', '01/2027', 2);

        $ids = StockBatch::query()->where('product_variant_id', $panadol->getKey())
            ->sellableOn(CarbonImmutable::parse('2026-12-01'), 0)->pluck('id')->all();

        $this->assertSame([$jan->getKey()], $ids);
        $this->assertSame('2026-12-31', StockBatch::sellableCutoff(CarbonImmutable::parse('2026-12-01'), 30));
    }

    /**
     * The scope lists exactly $expected at General Pharmacy, in order; a sale of all of it
     * takes exactly those batches in that order, and one unit more is refused.
     *
     * @param  list<StockBatch>  $expected
     */
    private function assertSellableMatchesFefo(ProductVariant $variant, array $expected, int $minDays): void
    {
        $batches = app(BatchService::class);
        $this->assertSame($minDays, $batches->minDaysToExpiry($variant));

        $sellable = StockBatch::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('location_id', $this->general->getKey())
            ->sellableOn($batches->today(), $batches->minDaysToExpiry($variant))
            ->get();

        $this->assertSame(array_map(fn (StockBatch $batch): int => $batch->getKey(), $expected), $sellable->modelKeys());

        $cutoff = StockBatch::sellableCutoff($batches->today(), $minDays);
        $this->assertTrue($sellable->every(fn (StockBatch $batch): bool => $batch->isSellableFrom($cutoff)));

        $total = (float) $sellable->sum(fn (StockBatch $batch): float => (float) $batch->quantity);

        try {
            $this->sell([new CartLine($variant->getKey(), $total + 1)]);
            $this->fail('FEFO sold more than the sellable batches hold.');
        } catch (PosException) {
            // Held back: expired, quarantined, too close to expiry.
        }

        $sale = $this->sell([new CartLine($variant->getKey(), $total)]);

        $taken = SaleItemBatch::query()->where('sale_item_id', $sale->items->sole()->getKey())->orderBy('id')->get();
        $this->assertSame($sellable->modelKeys(), $taken->pluck('stock_batch_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(
            $sellable->map(fn (StockBatch $batch): float => (float) $batch->quantity)->all(),
            $taken->map(fn (SaleItemBatch $piece): float => (float) $piece->quantity)->all(),
        );

        $this->assertLedgerMatches();
    }
}
