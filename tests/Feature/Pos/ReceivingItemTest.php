<?php

namespace Tests\Feature\Pos;

use App\Enums\DosageForm;
use App\Enums\DrugSchedule;
use App\Enums\TaxCategory;
use App\Exceptions\PosException;
use App\Exceptions\PossibleDuplicateItem;
use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\VariantUnit;
use App\Services\CatalogSyncService;
use App\Services\Data\CartLine;
use App\Services\Data\DrugData;
use App\Services\Data\PurchaseLine;
use App\Services\Data\QuickItemData;
use App\Services\Data\ReceiptLine;
use App\Services\ProductCatalogService;
use App\Services\PurchaseOrderService;
use App\Services\ReceivingItemService;
use Tests\Feature\Pos\Concerns\WorksWithPurchasing;

/**
 * Finding and creating items on purchase order and goods received lines
 * (docs/PHARMACY.md §4.9, as built): search by name, generic, brand,
 * strength, SKU and any barcode; creating a missing item, normal for a
 * product manager and needs_review (receivable, not sellable) for someone
 * who only receives; the duplicate guard; the audit entry naming the
 * document; and the review that puts the item on sale.
 */
class ReceivingItemTest extends BatchTestCase
{
    use WorksWithPurchasing;

    private function items(): ReceivingItemService
    {
        return app(ReceivingItemService::class);
    }

    /** Someone who may receive goods but not manage products (a future storekeeper). */
    private function receiver(): User
    {
        $receiver = User::factory()->create(['email' => 'stores@clinic.test']);
        $receiver->forceFill(['shop_id' => $this->shop->getKey()])->save();
        $receiver->givePermissionTo(['admin.access', 'purchasing.receive']);

        return $receiver;
    }

    /** Panadol with its brand, strength, a barcode and a barcoded Box of 30. */
    private function panadol(): ProductVariant
    {
        return app(ProductCatalogService::class)->create(
            ['name' => 'Panadol 500 mg Tablets', 'unit' => 'Tablet'],
            [['barcode' => '4790000000002', 'price' => 2_000, 'packs' => [['name' => 'Box of 30', 'factor' => 30, 'barcode' => '4790000000101', 'price' => 50_000]]]],
            user: $this->owner,
            drug: new DrugData(['Paracetamol'], DosageForm::Tablet, DrugSchedule::Otc, brandName: 'Panadol', strengthValue: 500, strengthUnit: 'mg'),
        )->variants()->with('product')->firstOrFail();
    }

    private function amoxil(): ProductVariant
    {
        return app(ProductCatalogService::class)->create(
            ['name' => 'Amoxil 250 mg Capsules', 'unit' => 'Capsule'],
            [['price' => 3_000]],
            user: $this->owner,
            drug: new DrugData(['Amoxicillin'], DosageForm::Capsule, DrugSchedule::PrescriptionOnly, brandName: 'Amoxil', strengthValue: 250, strengthUnit: 'mg'),
        )->variants()->with('product')->firstOrFail();
    }

    /** A new tablet as the quick-create form sends it. */
    private function newTablet(string $name = 'Cetirizine 10 mg Tablets', array $generics = ['Cetirizine'], float $strength = 10, ?string $barcode = null, ?int $price = null, array $packs = [['name' => 'Box of 30', 'factor' => 30]]): QuickItemData
    {
        return new QuickItemData(
            name: $name,
            drug: new DrugData($generics, DosageForm::Tablet, DrugSchedule::Otc, strengthValue: $strength, strengthUnit: 'mg'),
            unit: 'Tablet',
            barcode: $barcode,
            packs: $packs,
            price: $price,
        );
    }

    /** @param  callable(): mixed  $attempt */
    private function refused(callable $attempt, string $message, string $exception = PosException::class): void
    {
        try {
            $attempt();
            $this->fail("Expected: {$message}");
        } catch (PosException $caught) {
            $this->assertInstanceOf($exception, $caught);
            $this->assertStringContainsString($message, $caught->getMessage());
        }
    }

    public function test_items_are_found_by_name_generic_brand_strength_sku_and_any_barcode(): void
    {
        $panadol = $this->panadol();
        $amoxil = $this->amoxil();
        $box = VariantUnit::query()->where('barcode', '4790000000101')->sole();
        $keys = fn (string $term): array => array_map(strval(...), array_keys($this->items()->search($term)));

        $this->assertSame([(string) $panadol->getKey()], $keys('parace'));          // generic
        $this->assertSame([(string) $amoxil->getKey()], $keys('AMOXIL'));           // brand, any case
        $this->assertSame([(string) $amoxil->getKey()], $keys('amoxicillin 250'));  // generic and strength
        $this->assertSame([(string) $panadol->getKey()], $keys('500mg'));           // strength with its unit
        $this->assertSame([(string) $panadol->getKey()], $keys($panadol->sku));
        $this->assertSame([], $keys('amox 500'));                                   // every word must match

        // A scanned barcode finds just its item; a pack's barcode brings the pack.
        $this->assertSame([(string) $panadol->getKey()], $keys('4790000000002'));
        $this->assertSame([$panadol->getKey().':'.$box->getKey() => "{$panadol->sku} — Panadol 500 mg Tablets · Box of 30"], $this->items()->search('4790000000101'));
    }

    public function test_a_product_manager_creates_a_normal_item_and_without_a_price_it_is_not_for_sale_yet(): void
    {
        $variant = $this->items()->create($this->newTablet(barcode: '4791111111111'), $this->owner);
        $product = $variant->product;

        $this->assertSame([false, true, true, true, TaxCategory::Exempt, 'Tablet'], [$product->needs_review, $product->created_while_receiving, $product->track_batches, $product->whole_units_only, $product->tax_category, $product->unit]);
        $this->assertSame(['Cetirizine'], $product->generics->pluck('name')->all());
        $this->assertSame('4791111111111', $variant->barcode);
        $this->assertSame([], $variant->units()->with('prices')->get()->flatMap->prices->pluck('price')->all(), 'No price at all, not a price of 0.');

        $this->receive($variant, 'C1', '12/2027', 30);
        $this->refused(fn () => $this->sell([new CartLine($variant->getKey(), 1)]), 'has no price');

        // With prices typed in, it is for sale at once.
        $priced = $this->items()->create($this->newTablet('Loratadine 10 mg Tablets', ['Loratadine'], price: 1_500, packs: [['name' => 'Box of 30', 'factor' => 30, 'price' => 40_000]]), $this->owner);
        $this->receive($priced, 'L1', '12/2027', 30);
        $this->assertSame(1_500, $this->sell([new CartLine($priced->getKey(), 1)])->grand_total);
    }

    public function test_an_item_created_by_someone_who_only_receives_is_received_but_not_sold_until_reviewed(): void
    {
        $receiver = $this->receiver();
        $variant = $this->items()->create($this->newTablet(barcode: '4791111111111', price: 1_500, packs: [['name' => 'Box of 30', 'factor' => 30, 'price' => 40_000]]), $receiver);
        $product = $variant->product;

        $this->assertTrue($product->needs_review);
        $this->assertSame([], $variant->units()->with('prices')->get()->flatMap->prices->pluck('price')->all(), 'Prices wait for the review.');

        // Receivable: on a GRN by the same person.
        $this->postReceipt($this->supplier(), [new ReceiptLine($variant->getKey(), 2, $this->unit($variant, 'Box of 30'), unitCost: 30_000, batchNo: 'C1', expiry: '12/2027')], user: $receiver);
        $this->assertSame(60.0, $variant->stockAt($this->general));

        // Not sellable: the till can't find it, the offline catalog leaves it out, checkout refuses it.
        $this->actingAs($this->cashier)->getJson(route('pos.search', ['device' => $this->till->uuid, 'q' => 'cetirizine']))->assertOk()->assertJsonPath('results', []);
        $this->actingAs($this->cashier)->getJson(route('pos.search', ['device' => $this->till->uuid, 'q' => '4791111111111']))->assertOk()->assertJsonPath('exact', null);
        $catalog = app(CatalogSyncService::class);
        $this->assertNotContains($variant->getKey(), array_column($catalog->changes($this->till, null)['items'], 'variant_id'));
        $this->assertContains($variant->getKey(), $catalog->changes($this->till, now()->subHour())['deleted']);
        $this->refused(fn () => $this->sell([new CartLine($variant->getKey(), 1)]), 'waiting for an admin to review it');

        // Only a product manager reviews it, once.
        $this->refused(fn () => $this->items()->markReviewed($product, $receiver), 'permission');
        $this->items()->markReviewed($product, $this->owner);
        $this->assertSame([false, $this->owner->getKey()], [$product->fresh()?->needs_review, $product->fresh()?->reviewed_by]);
        $this->refused(fn () => $this->items()->markReviewed($product->refresh(), $this->owner), 'already been reviewed');

        // Reviewed but still unpriced: not for sale until it has a price.
        $this->refused(fn () => $this->sell([new CartLine($variant->getKey(), 1)]), 'has no price');
        $this->unit($variant, 'Tablet', model: true)->prices()->create(['product_variant_id' => $variant->getKey(), 'price_list_id' => $this->shop->defaultPriceList()->getKey(), 'min_quantity' => 1, 'price' => 1_500]);

        $this->assertSame(1_500, $this->sell([new CartLine($variant->getKey(), 1)])->grand_total);
        $this->assertContains($variant->getKey(), array_column($catalog->changes($this->till, null)['items'], 'variant_id'));
        $this->actingAs($this->cashier)->getJson(route('pos.search', ['device' => $this->till->uuid, 'q' => '4791111111111']))->assertJsonPath('exact.variant_id', $variant->getKey());
    }

    public function test_a_barcode_already_in_use_is_refused_outright(): void
    {
        $panadol = $this->panadol();

        $this->refused(fn () => $this->items()->create($this->newTablet(barcode: '4790000000002'), $this->owner, createAnyway: true), "already on {$panadol->sku}");
        $this->refused(fn () => $this->items()->create($this->newTablet(packs: [['name' => 'Box of 30', 'factor' => 30, 'barcode' => '4790000000101']]), $this->owner, createAnyway: true), 'already on');
        $this->assertSame(1, Product::query()->count());
    }

    public function test_a_possible_duplicate_is_a_warning_the_user_answers(): void
    {
        $panadol = $this->panadol();

        // Same generic, strength and form under another name (a different brand of paracetamol).
        try {
            $this->items()->create($this->newTablet('Calpol Tablets', ['paracetamol'], 500), $this->owner);
            $this->fail('A possible duplicate went in without a warning.');
        } catch (PossibleDuplicateItem $warning) {
            $this->assertSame([$panadol->getKey()], $warning->matches->pluck('id')->all());
            $this->assertStringContainsString('Use the existing item, or create it anyway', $warning->getMessage());
        }

        // Nearly the same name.
        $this->refused(fn () => $this->items()->create($this->newTablet('panadol 500mg tablets', ['Acetaminophen'], 1_000), $this->owner), 'looks like an item already in the catalog', PossibleDuplicateItem::class);

        // A different strength of the same generic, or another medicine, is not a duplicate.
        $this->assertCount(0, $this->items()->possibleDuplicates($this->newTablet('Panadol Extra', ['Paracetamol'], 1_000)));
        $this->assertCount(0, $this->items()->possibleDuplicates($this->newTablet()));

        // Answered "create anyway".
        $calpol = $this->items()->create($this->newTablet('Calpol Tablets', ['Paracetamol'], 500), $this->owner, createAnyway: true);
        $this->assertSame('Calpol Tablets', $calpol->product->name);
    }

    public function test_the_creation_is_audited_with_the_document_it_was_entered_on(): void
    {
        $receiver = $this->receiver();
        $supplier = $this->supplier();
        $panadol = $this->panadol();

        // On a GRN draft being edited: linked and audited at once.
        $draft = $this->draftReceipt($supplier, [new ReceiptLine($panadol->getKey(), 10, batchNo: 'P1', expiry: '12/2027')]);
        $item = $this->items()->create($this->newTablet(), $receiver, $draft);

        $this->assertTrue($item->product->fresh()?->createdOn?->is($draft));
        $entry = AuditLog::query()->where('event', 'created_while_receiving')->sole();
        $this->assertSame([$receiver->getKey(), $item->product_id], [$entry->user_id, $entry->auditable_id]);
        $this->assertSame('goods received note draft', $entry->new_values['document'] ?? null);
        $this->assertSame($draft->uuid, $entry->new_values['document_uuid'] ?? null);
        $this->assertTrue($entry->new_values['needs_review'] ?? null);

        // On a new order: linked and audited when the order is first saved, once.
        $other = $this->items()->create($this->newTablet('Loratadine 10 mg Tablets', ['Loratadine']), $this->owner);
        $this->assertSame(1, AuditLog::query()->where('event', 'created_while_receiving')->count());

        $orders = app(PurchaseOrderService::class);
        $order = $orders->create($supplier, $this->general, [new PurchaseLine($other->getKey(), 30, unitCost: 1_000), new PurchaseLine($item->getKey(), 30, unitCost: 1_000)], $this->owner);
        $orders->update($order, $supplier, $this->general, [new PurchaseLine($other->getKey(), 60, unitCost: 1_000)], $this->owner);

        $this->assertTrue($other->product->fresh()?->createdOn?->is($order));
        $this->assertTrue($item->product->fresh()?->createdOn?->is($draft), 'Already linked to the GRN it was created on.');
        $entries = AuditLog::query()->where('event', 'created_while_receiving')->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame('purchase order PO-000001', $entries[1]->new_values['document'] ?? null);
        $this->assertInstanceOf(PurchaseOrder::class, $other->product->fresh()?->createdOn);
        $this->assertInstanceOf(GoodsReceipt::class, $item->product->fresh()?->createdOn);
    }

    public function test_who_may_create_items_while_receiving(): void
    {
        $this->refused(fn () => $this->items()->create($this->newTablet(), $this->cashier), 'permission');

        $orderer = User::factory()->create(['email' => 'buyer@clinic.test']);
        $orderer->forceFill(['shop_id' => $this->shop->getKey()])->save();
        $orderer->givePermissionTo(['admin.access', 'purchasing.manage']);

        $this->assertTrue($this->items()->create($this->newTablet(), $orderer)->product->needs_review);
    }

    /** A unit of the item by name: its id, or the model. */
    private function unit(ProductVariant $variant, string $name, bool $model = false): int|VariantUnit
    {
        $unit = VariantUnit::query()->where('product_variant_id', $variant->getKey())->where('name', $name)->sole();

        return $model ? $unit : (int) $unit->getKey();
    }
}
