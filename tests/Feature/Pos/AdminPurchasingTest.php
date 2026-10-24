<?php

namespace Tests\Feature\Pos;

use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierPaymentMethod;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Resources\GoodsReceipts\Pages\CreateGoodsReceipt;
use App\Filament\Resources\GoodsReceipts\Pages\EditGoodsReceipt;
use App\Filament\Resources\GoodsReceipts\Pages\ListGoodsReceipts;
use App\Filament\Resources\GoodsReceipts\Pages\ViewGoodsReceipt;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SupplierReturns\Pages\CreateSupplierReturn;
use App\Filament\Resources\SupplierReturns\Pages\ViewSupplierReturn;
use App\Filament\Resources\SupplierReturns\SupplierReturnResource;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Filament\Resources\Suppliers\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\SupplierOpeningBalance;
use App\Models\SupplierPayment;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Services\Data\PurchaseLine;
use App\Services\Data\ReceiptLine;
use App\Services\ModuleService;
use App\Services\SupplierService;
use App\Support\CurrentShop;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithPurchasing;

/**
 * The purchasing screens (pharmacy clinic): suppliers with their balance
 * and payments, orders made, sent and received from their page, goods
 * received drafted, posted, reversed and printed, supplier returns by batch,
 * and who may see what.
 */
class AdminPurchasingTest extends BatchTestCase
{
    use WorksWithPurchasing;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
    }

    public function test_suppliers_are_added_and_show_what_is_owed(): void
    {
        Livewire::test(ListSuppliers::class)
            ->callAction('create', data: ['name' => 'Hemas Pharmaceuticals', 'contact_name' => 'Nimal', 'phone' => '011 000 0000', 'payment_terms_days' => 30, 'is_active' => true])
            ->assertHasNoActionErrors();

        Livewire::test(ListSuppliers::class)
            ->callAction('create', data: ['name' => 'hemas pharmaceuticals', 'is_active' => true])
            ->assertNotified('There is already a supplier called hemas pharmaceuticals.');

        $supplier = Supplier::query()->sole();
        $panadol = $this->medicine();
        $this->postReceipt($supplier, [new ReceiptLine($panadol->getKey(), 10, unitCost: 1_000, batchNo: 'A', expiry: '12/2027')]);

        Livewire::test(ListSuppliers::class)
            ->assertCanSeeTableRecords([$supplier])
            ->assertTableColumnStateSet('balance', 10_000, $supplier);

        Livewire::test(ViewSupplier::class, ['record' => $supplier->getRouteKey()])
            ->assertSee('LKR 100.00')
            ->callAction('recordPayment', data: ['amount' => '40', 'paid_on' => '2026-10-18', 'method' => 'cheque', 'reference' => 'CHQ 1'])
            ->assertHasNoActionErrors()
            ->assertRedirect(SupplierResource::getUrl('view', ['record' => $supplier]));

        $this->assertSame([4_000, 'CHQ 1'], [SupplierPayment::query()->sole()->amount, SupplierPayment::query()->sole()->reference]);

        Livewire::test(ViewSupplier::class, ['record' => $supplier->getRouteKey()])
            ->assertSee('LKR 60.00')
            ->callAction('edit', data: ['name' => 'Hemas Pharmaceuticals', 'payment_terms_days' => 45, 'is_active' => true])
            ->assertHasNoActionErrors();

        $this->assertSame(45, $supplier->fresh()?->payment_terms_days);
    }

    public function test_the_opening_balance_and_payments_are_corrected_by_reversing_them_from_the_supplier_page(): void
    {
        $supplier = $this->supplier();
        $page = fn () => Livewire::test(ViewSupplier::class, ['record' => $supplier->getRouteKey()]);

        $page()
            ->assertActionVisible('openingBalance')
            ->assertActionHidden('reverseOpeningBalance')
            ->callAction('openingBalance', data: ['amount' => '1500', 'as_of' => '2026-10-01', 'note' => 'Old books'])
            ->assertHasNoActionErrors();

        $this->assertSame(150_000, app(SupplierService::class)->balance($supplier)['balance']);
        $page()
            ->assertSee('LKR 1,500.00')
            ->assertSee('As of 01/10/2026: Old books')
            ->assertActionHidden('openingBalance')
            ->callAction('reverseOpeningBalance', data: ['reason' => 'Wrong supplier'])
            ->assertHasNoActionErrors();

        $this->assertSame([0, 2], [app(SupplierService::class)->balance($supplier)['balance'], SupplierOpeningBalance::query()->count()]);
        $page()->assertActionVisible('openingBalance');

        $payment = app(SupplierService::class)->recordPayment($supplier, 40_000, SupplierPaymentMethod::Cash, $this->owner);
        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $supplier, 'pageClass' => ViewSupplier::class])
            ->assertCanSeeTableRecords([$payment])
            ->callTableAction('reversePayment', $payment, data: ['reason' => 'Entered twice'])
            ->assertHasNoTableActionErrors();

        $this->assertSame([2, 0], [SupplierPayment::query()->count(), app(SupplierService::class)->balance($supplier)['payments']]);
        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $supplier, 'pageClass' => ViewSupplier::class])
            ->assertTableActionHidden('reversePayment', $payment)
            ->assertTableActionHidden('reversePayment', SupplierPayment::query()->whereNotNull('reversal_of_id')->sole());
    }

    public function test_an_order_is_made_sent_and_received_from_its_page(): void
    {
        $panadol = $this->medicine();
        $supplier = $this->supplier();

        Livewire::test(CreatePurchaseOrder::class)
            ->fillForm([
                'supplier_id' => $supplier->getKey(),
                'location_id' => $this->doctors->getKey(),
                'expected_on' => '2026-10-25',
                'lines' => [['variant_id' => $panadol->getKey(), 'unit_id' => $this->boxOf($panadol), 'quantity' => 10, 'unit_cost' => '90']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $order = PurchaseOrder::query()->sole();
        $this->assertSame([PurchaseOrderStatus::Draft, $this->doctors->getKey(), 90_000], [$order->status, $order->location_id, $order->total]);

        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('receive')
            ->callAction('send')
            ->assertRedirect(PurchaseOrderResource::getUrl('view', ['record' => $order]));
        $this->assertSame(PurchaseOrderStatus::Sent, $order->fresh()?->status);

        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('receive')
            ->assertActionHidden('edit');

        // "Receive goods" opens a GRN with the order's supplier, location and what is still to come.
        $row = null;
        Livewire::withQueryParams(['order' => $order->uuid])
            ->test(CreateGoodsReceipt::class)
            ->assertFormSet(function (array $state) use ($supplier, $order, $panadol, &$row): array {
                $row = array_key_first($state['lines']);
                $line = $state['lines'][$row];
                $this->assertEquals([$supplier->getKey(), $this->doctors->getKey(), $order->getKey(), 'PO-000001'], [$state['supplier_id'], $state['location_id'], $state['purchase_order_id'], $state['purchase_order']]);
                $this->assertEquals([$order->items->sole()->getKey(), $panadol->getKey(), $this->boxOf($panadol), 10, 90], [$line['purchase_order_item_id'], $line['variant_id'], $line['unit_id'], $line['quantity'], $line['unit_cost']]);

                return [];
            })
            // 6 of the 10 boxes came: the rest stays on the order.
            ->fillForm(["lines.{$row}.quantity" => 6, "lines.{$row}.batch_no" => 'P1', "lines.{$row}.expiry" => '12/2027'])
            ->call('create')
            ->assertHasNoFormErrors();

        $grn = GoodsReceipt::query()->sole();
        $this->assertSame($order->items->sole()->getKey(), $grn->items->sole()->purchase_order_item_id);
        Livewire::test(ViewGoodsReceipt::class, ['record' => $grn->getRouteKey()])->callAction('post');

        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->fresh()?->status);
        $this->assertSame(['P1' => 60.0], $this->batchQuantities($panadol, $this->doctors));

        $this->get(route('purchasing.print.order', ['order' => $order]))
            ->assertOk()
            ->assertSee('PO-000001')
            ->assertSee("Doctor's Medicines")
            ->assertSee('Hemas Pharmaceuticals');
    }

    public function test_goods_are_drafted_posted_printed_and_reversed_from_their_pages(): void
    {
        $panadol = $this->medicine();
        $supplier = $this->supplier();

        Livewire::test(CreateGoodsReceipt::class)
            ->assertFormFieldExists('lines')
            ->fillForm([
                'supplier_id' => $supplier->getKey(),
                'location_id' => $this->general->getKey(),
                'received_on' => '2026-10-18',
                'supplier_invoice_no' => 'INV-881',
                'invoice_discount' => '5',
                'lines' => [['variant_id' => $panadol->getKey(), 'unit_id' => $this->boxOf($panadol), 'quantity' => 10, 'free_quantity' => 2, 'unit_cost' => '90', 'discount' => '45', 'batch_no' => 'pn-7', 'expiry' => '12/2027']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $grn = GoodsReceipt::query()->sole();
        $this->assertSame([GoodsReceiptStatus::Draft, 85_000], [$grn->status, $grn->total]);

        // A draft can be edited, and shows the expiry as printed.
        Livewire::test(EditGoodsReceipt::class, ['record' => $grn->getRouteKey()])
            ->assertFormSet(function (array $state): array {
                $line = array_values($state['lines'])[0];
                $this->assertEquals(['12/2027', 90, 45, 5], [$line['expiry'], $line['unit_cost'], $line['discount'], $state['invoice_discount']]);

                return [];
            })
            ->fillForm(['invoice_discount' => '0'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(85_500, $grn->fresh()?->total);

        Livewire::test(ViewGoodsReceipt::class, ['record' => $grn->getRouteKey()])
            ->assertActionHidden('print')
            ->assertActionHidden('reverse')
            ->callAction('post')
            ->assertRedirect(GoodsReceiptResource::getUrl('view', ['record' => $grn]));

        // (Notifications are asserted where no earlier page redirected: Filament keeps a redirect's in the session.)
        $this->assertSame(['GRN-000001', GoodsReceiptStatus::Posted], [$grn->fresh()?->grn_number, $grn->fresh()?->status]);

        $this->assertSame(['PN-7' => 120.0], $this->batchQuantities($panadol));
        $this->get(route('purchasing.print.receipt', ['receipt' => $grn]))
            ->assertOk()
            ->assertSee('GRN-000001')
            ->assertSee('INV-881')
            ->assertSee('PN-7')
            ->assertSee('exp 12/2027')
            ->assertSee('LKR 855.00');

        // Posted: read-only.
        $this->get(GoodsReceiptResource::getUrl('edit', ['record' => $grn]))->assertForbidden();
        Livewire::test(ViewGoodsReceipt::class, ['record' => $grn->getRouteKey()])
            ->assertActionHidden('post')
            ->assertActionHidden('edit')
            ->callAction('reverse', data: ['reason' => 'Wrong cost typed'])
            ->assertHasNoActionErrors();

        $this->assertSame('GRN-000002', $grn->fresh()?->reversal?->grn_number);

        $this->assertSame([], $this->batchQuantities($panadol));
        $this->get(route('purchasing.print.receipt', ['receipt' => GoodsReceipt::query()->where('grn_number', 'GRN-000002')->sole()]))
            ->assertOk()
            ->assertSee('Reverses GRN-000001');

        Livewire::test(ListGoodsReceipts::class)->assertCanSeeTableRecords(GoodsReceipt::query()->get());
    }

    public function test_tax_on_the_invoice_or_its_lines_is_entered_on_the_form_and_printed(): void
    {
        $panadol = $this->medicine();
        $amoxil = $this->medicine('Amoxil 250 mg Capsules');
        $supplier = $this->supplier();

        Livewire::test(CreateGoodsReceipt::class)
            ->fillForm([
                'supplier_id' => $supplier->getKey(),
                'location_id' => $this->general->getKey(),
                'received_on' => '2026-10-18',
                'invoice_tax' => '180',
                'lines' => [
                    ['variant_id' => $panadol->getKey(), 'quantity' => 60, 'unit_cost' => '10', 'batch_no' => 'P1', 'expiry' => '12/2027'],
                    ['variant_id' => $amoxil->getKey(), 'quantity' => 40, 'unit_cost' => '10', 'batch_no' => 'A1', 'expiry' => '12/2027'],
                ],
            ])
            ->assertSee('total LKR 1,180.00 after the invoice discount and tax')
            ->call('create')
            ->assertHasNoFormErrors();

        $grn = GoodsReceipt::query()->sole();
        $this->assertSame([18_000, 118_000], [$grn->invoice_tax, $grn->total]);
        $this->assertSame([10_800, 7_200], $grn->items->pluck('invoice_tax_share')->all());

        // Moved onto the lines instead: the invoice's tax is cleared, each line keeps its own.
        Livewire::test(EditGoodsReceipt::class, ['record' => $grn->getRouteKey()])
            ->assertFormSet(['invoice_tax' => '180.00'])
            ->fillForm([
                'invoice_tax' => null,
                'lines' => [
                    ['variant_id' => $panadol->getKey(), 'quantity' => 60, 'unit_cost' => '10', 'tax' => '108', 'batch_no' => 'P1', 'expiry' => '12/2027'],
                    ['variant_id' => $amoxil->getKey(), 'quantity' => 40, 'unit_cost' => '10', 'batch_no' => 'A1', 'expiry' => '12/2027'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $grn->refresh();
        $this->assertSame([0, 10_800, 110_800], [$grn->invoice_tax, $grn->line_tax_total, $grn->total]);

        Livewire::test(ViewGoodsReceipt::class, ['record' => $grn->getRouteKey()])
            ->assertSee('+ LKR 108.00 tax')
            ->callAction('post');

        $this->get(route('purchasing.print.receipt', ['receipt' => $grn]))
            ->assertOk()
            ->assertSeeInOrder(['Discount', 'Tax', 'Amount'])
            ->assertSeeInOrder(['Tax on lines', 'LKR 108.00', 'Total', 'LKR 1,108.00']);

        // Both at once would count the VAT twice.
        Livewire::test(CreateGoodsReceipt::class)
            ->fillForm([
                'supplier_id' => $supplier->getKey(),
                'location_id' => $this->general->getKey(),
                'invoice_tax' => '18',
                'lines' => [['variant_id' => $panadol->getKey(), 'quantity' => 10, 'unit_cost' => '10', 'tax' => '18', 'batch_no' => 'P1', 'expiry' => '12/2027']],
            ])
            ->call('create')
            ->assertNotified('Enter the tax on the lines or for the whole invoice, not both.');
        $this->assertSame(1, GoodsReceipt::query()->count());
    }

    public function test_a_grn_without_tax_prints_no_tax_column(): void
    {
        $panadol = $this->medicine();
        $grn = $this->postReceipt($this->supplier(), [new ReceiptLine($panadol->getKey(), 10, unitCost: 1_000, batchNo: 'P1', expiry: '12/2027')]);

        $this->get(route('purchasing.print.receipt', ['receipt' => $grn]))
            ->assertOk()
            ->assertDontSee('<th class="num">Tax</th>', false)
            ->assertDontSee('Invoice tax');
    }

    public function test_orders_and_goods_received_start_at_the_default_location_when_the_shop_receives_centrally(): void
    {
        // The owner works at Doctor's Medicines, but the clinic receives everything at General Pharmacy.
        $this->owner->forceFill(['default_location_id' => $this->doctors->getKey()])->save();
        $this->assertTrue($this->shop->setting('receive_at_default_location'));

        Livewire::test(CreateGoodsReceipt::class)->assertFormSet(['location_id' => $this->general->getKey()]);
        Livewire::test(CreatePurchaseOrder::class)->assertFormSet(['location_id' => $this->general->getKey()]);
        // Supplier returns go out from where the user works.
        Livewire::test(CreateSupplierReturn::class)->assertFormSet(['location_id' => $this->doctors->getKey()]);

        $this->shop->update(['settings' => [...$this->shop->settings ?? [], 'receive_at_default_location' => false]]);

        Livewire::test(CreateGoodsReceipt::class)->assertFormSet(['location_id' => $this->doctors->getKey()]);
        Livewire::test(CreatePurchaseOrder::class)->assertFormSet(['location_id' => $this->doctors->getKey()]);
    }

    public function test_posting_from_the_page_shows_why_it_was_refused(): void
    {
        $panadol = $this->medicine();
        $draft = $this->draftReceipt($this->supplier(), [new ReceiptLine($panadol->getKey(), 10, unitCost: 1_000, batchNo: 'SD1', expiry: '12/2026')]);

        Livewire::test(ViewGoodsReceipt::class, ['record' => $draft->getRouteKey()])
            ->callAction('post')
            ->assertNotified('Batch SD1 expires on 31/12/2026, within 90 days. Tick "Accept short expiry" to receive it anyway (owner only).');

        $this->assertSame(GoodsReceiptStatus::Draft, $draft->fresh()?->status);
        $this->get(route('purchasing.print.receipt', ['receipt' => $draft]))->assertNotFound();
    }

    public function test_a_supplier_return_is_picked_by_batch_and_printed(): void
    {
        $panadol = $this->medicine();
        $supplier = $this->supplier();
        $batch = $this->receive($panadol, 'A', '12/2027', 30);

        Livewire::test(CreateSupplierReturn::class)
            ->fillForm([
                'supplier_id' => $supplier->getKey(),
                'location_id' => $this->general->getKey(),
                'reason' => 'damaged',
                'returned_on' => '2026-10-18',
                'lines' => [['variant_id' => $panadol->getKey(), 'batch_id' => $batch->getKey(), 'unit_id' => $this->boxOf($panadol), 'quantity' => 2]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $return = SupplierReturn::query()->sole();
        $this->assertSame(['SR-000001', 20_000], [$return->return_number, $return->total]);
        $this->assertSame(['A' => 10.0], $this->batchQuantities($panadol));

        Livewire::test(ViewSupplierReturn::class, ['record' => $return->getRouteKey()])
            ->callAction('creditNote', data: ['credit_note_no' => 'CN-5'])
            ->assertHasNoActionErrors();

        $this->get(route('purchasing.print.return', ['supplierReturn' => $return]))
            ->assertOk()
            ->assertSee('SR-000001')
            ->assertSee('CN-5')
            ->assertSee('Damaged')
            ->assertSee('LKR 200.00');
    }

    public function test_who_sees_what(): void
    {
        $supplier = $this->supplier();
        $order = $this->sentOrder($supplier, [new PurchaseLine($this->medicine()->getKey(), 1)]);
        $return = SupplierReturnResource::getUrl('index');

        // A cashier has no purchasing screens.
        $this->actingAs($this->cashier);
        foreach ([SupplierResource::getUrl('index'), PurchaseOrderResource::getUrl('index'), GoodsReceiptResource::getUrl('index'), $return] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get(route('purchasing.print.order', ['order' => $order]))->assertForbidden();

        // Someone who only receives goods (a future pharmacist role) sees orders and GRNs, not returns or balances.
        $receiver = User::factory()->create(['email' => 'receiver@clinic.test']);
        $receiver->forceFill(['shop_id' => $this->shop->getKey()])->save();
        $receiver->givePermissionTo(['admin.access', 'purchasing.receive']);
        $this->actingAs($receiver);

        $this->get(GoodsReceiptResource::getUrl('create'))->assertOk();
        $this->get(PurchaseOrderResource::getUrl('view', ['record' => $order]))->assertOk();
        $this->get(PurchaseOrderResource::getUrl('create'))->assertForbidden();
        $this->get($return)->assertForbidden();
        $this->assertFalse(SupplierResource::showsBalance());
        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('receive')
            ->assertActionHidden('cancel');

        // Another shop's documents are not found, even with purchasing on there.
        $other = Shop::query()->create(['name' => 'Other', 'code' => 'OTHER']);
        app(ModuleService::class)->enable($other, 'purchasing');
        $stranger = User::factory()->create(['email' => 'stranger@other.test']);
        $stranger->forceFill(['shop_id' => $other->getKey()])->save();
        $stranger->givePermissionTo(['admin.access', 'purchasing.manage']);
        $url = route('purchasing.print.order', ['order' => $order]);
        $this->useShop(null);
        app()->forgetInstance(CurrentShop::class);

        $this->actingAs($stranger)->get($url)->assertNotFound();
    }
}
