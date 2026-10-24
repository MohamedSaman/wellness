<?php

namespace Tests\Feature\Pos;

use App\Enums\DosageForm;
use App\Enums\DrugSchedule;
use App\Filament\Resources\GoodsReceipts\Pages\CreateGoodsReceipt;
use App\Filament\Resources\GoodsReceipts\Pages\EditGoodsReceipt;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Widgets\ItemsToReview;
use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VariantUnit;
use App\Services\Data\DrugData;
use App\Services\Data\ReceiptLine;
use App\Services\ProductCatalogService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithPurchasing;

/**
 * "Create new item" on goods received and purchase order lines: the item
 * search (and a scanned pack barcode), the quick-create form prefilled from
 * what was typed, the duplicate guard, the new item selected on its line
 * with the rest of the document untouched, and the review screens.
 */
class AdminReceivingItemTest extends BatchTestCase
{
    use WorksWithPurchasing;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
    }

    private function panadol(): ProductVariant
    {
        return app(ProductCatalogService::class)->create(
            ['name' => 'Panadol 500 mg Tablets', 'unit' => 'Tablet'],
            [['price' => 2_000, 'packs' => [['name' => 'Box of 30', 'factor' => 30, 'barcode' => '4790000000101', 'price' => 50_000]]]],
            user: $this->owner,
            drug: new DrugData(['Paracetamol'], DosageForm::Tablet, DrugSchedule::Otc, brandName: 'Panadol', strengthValue: 500, strengthUnit: 'mg'),
        )->variants()->with('product')->firstOrFail();
    }

    private function createOption(string $lineKey): TestAction
    {
        return TestAction::make('createOption')->schemaComponent("lines.{$lineKey}.variant_id", 'form');
    }

    /** Type into a line's item search, as the browser does. */
    private function search(Testable $page, string $lineKey, string $term): mixed
    {
        return $page->instance()->callSchemaComponentMethod("form.lines.{$lineKey}.variant_id", 'getSearchResultsForJs', ['search' => $term]);
    }

    private function receiver(): User
    {
        $receiver = User::factory()->create(['email' => 'stores@clinic.test']);
        $receiver->forceFill(['shop_id' => $this->shop->getKey()])->save();
        $receiver->givePermissionTo(['admin.access', 'purchasing.receive']);

        return $receiver;
    }

    public function test_an_item_missing_from_the_catalog_is_created_from_a_grn_line_and_selected_on_it(): void
    {
        $panadol = $this->panadol();
        $supplier = $this->supplier();

        $page = Livewire::test(CreateGoodsReceipt::class)->fillForm([
            'supplier_id' => $supplier->getKey(),
            'location_id' => $this->general->getKey(),
            'supplier_invoice_no' => 'INV-77',
            'lines' => [
                ['variant_id' => $panadol->getKey(), 'quantity' => 2, 'unit_cost' => '450', 'batch_no' => 'P1', 'expiry' => '12/2027'],
                ['quantity' => 3, 'unit_cost' => '300', 'batch_no' => 'C1', 'expiry' => '12/2027'],
            ],
        ]);
        [$first, $second] = array_keys($page->get('data.lines'));

        // Typed, nothing found: the new item starts with what was typed.
        $this->assertSame([], $this->search($page, $second, 'Cetirizine 10 mg Tablets'));

        $page->mountAction($this->createOption($second))
            ->assertSchemaStateSet(['name' => 'Cetirizine 10 mg Tablets', 'is_medicine' => true, 'tax_category' => 'exempt'])
            ->setActionData(['dosage_form' => 'tablet'])
            ->assertSchemaStateSet(['unit' => 'Tablet', 'allow_unit_sale' => true])
            ->setActionData(['generic_names' => ['Cetirizine'], 'strength_value' => 10, 'strength_unit' => 'mg', 'schedule' => 'otc', 'barcode' => '4792222222222'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $cetirizine = ProductVariant::query()->where('barcode', '4792222222222')->with('product.generics')->sole();
        $this->assertSame(['Cetirizine 10 mg Tablets', false, ['Cetirizine']], [$cetirizine->product->name, $cetirizine->product->needs_review, $cetirizine->product->generics->pluck('name')->all()]);
        $this->assertSame(['Tablet', 'Box of 30', 'Box of 100'], $cetirizine->units()->orderBy('sort_order')->pluck('name')->all(), 'The tablet defaults were applied.');

        // Selected on its line; the rest of the GRN is as it was.
        $page->assertFormSet(fn (array $state): array => [
            'supplier_invoice_no' => 'INV-77',
            "lines.{$first}.variant_id" => $panadol->getKey(),
            "lines.{$second}.variant_id" => (string) $cetirizine->getKey(),
            "lines.{$second}.quantity" => 3,
            "lines.{$second}.batch_no" => 'C1',
        ]);

        $page->call('create')->assertHasNoFormErrors();
        $grn = GoodsReceipt::query()->sole();
        $this->assertSame([$panadol->getKey(), $cetirizine->getKey()], $grn->items->pluck('product_variant_id')->all());

        // The creation is in the audit log against this GRN.
        $this->assertSame('goods received note draft', AuditLog::query()->where('event', 'created_while_receiving')->sole()->new_values['document'] ?? null);
        $this->assertTrue($cetirizine->product->fresh()?->createdOn?->is($grn));
    }

    public function test_a_scanned_pack_barcode_picks_the_item_and_its_pack(): void
    {
        $panadol = $this->panadol();
        $box = VariantUnit::query()->where('barcode', '4790000000101')->sole();

        $page = Livewire::test(CreatePurchaseOrder::class);
        $key = (string) array_key_first($page->get('data.lines'));

        $options = $this->search($page, $key, '4790000000101');
        $this->assertSame("{$panadol->getKey()}:{$box->getKey()}", $options[0]['value'] ?? null);

        $page->set("data.lines.{$key}.variant_id", $options[0]['value'])
            ->assertSet("data.lines.{$key}.variant_id", $panadol->getKey())
            ->assertSet("data.lines.{$key}.unit_id", $box->getKey());
    }

    public function test_a_possible_duplicate_is_answered_on_the_form_and_a_taken_barcode_is_refused(): void
    {
        $panadol = $this->panadol();
        $page = Livewire::test(CreateGoodsReceipt::class);
        $key = (string) array_key_first($page->get('data.lines'));
        $this->search($page, $key, '4790000000101');

        // The scanned barcode belongs to a pack already: refused, whatever else is right.
        $page->mountAction($this->createOption($key))
            ->assertSchemaStateSet(['barcode' => '4790000000101', 'name' => null])
            ->setActionData(['name' => 'Calpol Tablets', 'dosage_form' => 'tablet', 'generic_names' => ['Paracetamol'], 'strength_value' => 500, 'strength_unit' => 'mg', 'schedule' => 'otc'])
            ->callMountedAction()
            ->assertHasActionErrors(['barcode']);

        // Without it, the same generic and strength asks: use the existing item, or create anyway.
        $page->setActionData(['barcode' => null])
            ->callMountedAction()
            ->assertHasActionErrors(['duplicate_choice' => 'required']);

        $page->setActionData(['duplicate_choice' => (string) $panadol->getKey()])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertSet("data.lines.{$key}.variant_id", (string) $panadol->getKey());

        $this->assertSame(1, Product::query()->count(), 'The existing item was used; nothing was created.');

        // "Create anyway" creates it.
        $page->mountAction($this->createOption($key))
            ->setActionData(['name' => 'Calpol Tablets', 'dosage_form' => 'tablet', 'generic_names' => ['Paracetamol'], 'strength_value' => 500, 'strength_unit' => 'mg', 'schedule' => 'otc', 'duplicate_choice' => 'new'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(['Calpol Tablets', 'Panadol 500 mg Tablets'], Product::query()->orderBy('name')->pluck('name')->all());
    }

    public function test_someone_who_only_receives_creates_an_item_for_review_without_prices(): void
    {
        $receiver = $this->receiver();
        $this->actingAs($receiver);
        $draft = $this->draftReceipt($this->supplier(), [new ReceiptLine($this->panadol()->getKey(), 1, batchNo: 'P1', expiry: '12/2027')], user: $this->owner);

        $page = Livewire::test(EditGoodsReceipt::class, ['record' => $draft->getRouteKey()]);
        $key = (string) array_key_first($page->get('data.lines'));

        $page->mountAction($this->createOption($key))
            ->assertActionMounted($this->createOption($key))
            ->assertMountedActionModalSee('an admin reviews it and sets its prices')
            ->assertMountedActionModalDontSee('Price per base unit')
            ->setActionData(['name' => 'Cetirizine 10 mg Tablets', 'dosage_form' => 'tablet', 'generic_names' => ['Cetirizine'], 'strength_value' => 10, 'strength_unit' => 'mg', 'schedule' => 'otc'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $item = Product::query()->where('name', 'Cetirizine 10 mg Tablets')->sole();
        $this->assertTrue($item->needs_review);
        $this->assertTrue($item->createdOn?->is($draft), 'Linked at once: the draft already exists.');

        // The admin sees it to review: the dashboard tile, the filter, the badge, then Mark as reviewed.
        $this->actingAs($this->owner);
        $this->assertTrue(ItemsToReview::canView());
        Livewire::test(ItemsToReview::class)->assertSee('Items to review')->assertSee('1');
        Livewire::test(ListProducts::class)
            ->filterTable('needs_review', true)
            ->assertCanSeeTableRecords([$item])
            ->assertCountTableRecords(1)
            ->assertTableColumnStateSet('review', 'Needs review', $item);

        Livewire::test(EditProduct::class, ['record' => $item->getRouteKey()])
            ->callAction('markReviewed')
            ->assertNotified('Cetirizine 10 mg Tablets reviewed: it is for sale');

        $this->assertFalse($item->fresh()?->needs_review);
        $this->assertFalse(ItemsToReview::canView());
        Livewire::test(EditProduct::class, ['record' => $item->getRouteKey()])->assertActionHidden('markReviewed');

        // Someone who only receives doesn't see the tile (they can't review).
        $this->actingAs($receiver);
        $this->assertFalse(ItemsToReview::canView());
    }
}
