<?php

namespace Database\Seeders;

use App\Enums\SupplierPaymentMethod;
use App\Enums\SupplierReturnReason;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Data\GoodsReceiptData;
use App\Services\Data\PurchaseLine;
use App\Services\Data\ReceiptLine;
use App\Services\Data\SupplierReturnLine;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseOrderService;
use App\Services\SupplierReturnService;
use App\Services\SupplierService;
use App\Support\CurrentShop;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * LOCAL DEVELOPMENT ONLY: purchasing in the demo clinic, on top of
 * DemoClinicStockSeeder's medicines, so the purchasing screens have
 * something to show at :8001. Everything goes through the services: two
 * suppliers, an order to Hemas partly delivered (GRN-000001, with free
 * goods and an invoice discount), an older Hemas invoice now overdue, a
 * delivery for Doctor's Medicines left as a draft to post, the expired
 * Amoxil batch returned (credit to allocate), a payment that pays the
 * oldest Hemas invoice in part, and what was owed to SPC at go-live: a
 * lump sum, due after SPC's terms, and an opening invoice due this week.
 * Runs once per clinic (skipped when it already has suppliers).
 */
class DemoClinicPurchasingSeeder extends Seeder
{
    public function run(CurrentShop $currentShop, SupplierService $suppliers, PurchaseOrderService $orders, GoodsReceiptService $receipts, SupplierReturnService $returns): void
    {
        $shop = Shop::query()->where('code', 'CLINIC')->firstOrFail();

        // All or nothing: a dev database whose demo stock was changed by hand is left as it was.
        $currentShop->runAs($shop, fn () => DB::transaction(function () use ($shop, $suppliers, $orders, $receipts, $returns): void {
            if (Supplier::query()->exists()) {
                return;
            }

            $owner = User::query()->where('email', 'owner@clinic.test')->firstOrFail();
            $general = Location::query()->where('code', 'GEN')->firstOrFail();
            $doctors = Location::query()->where('code', 'DOC')->firstOrFail();
            $month = fn (int $months): string => now($shop->timezone)->addMonths($months)->format('m/Y');
            $item = fn (string $name): ProductVariant => ProductVariant::query()->with('units')->whereHas('product', fn ($product) => $product->where('name', $name))->firstOrFail();
            $unit = fn (ProductVariant $variant, string $name): int => (int) $variant->units->firstWhere('name', $name)?->getKey();

            $hemas = $suppliers->create([
                'name' => 'Hemas Pharmaceuticals', 'contact_name' => 'Nimal Perera', 'phone' => '+94 11 000 0001',
                'email' => 'orders@hemas.test', 'address' => '36 Bristol Street, Colombo 1', 'tax_number' => 'VAT-114455',
                'payment_terms_days' => 30, 'notes' => 'Delivers Tuesdays and Fridays.',
            ], $owner);
            $spc = $suppliers->create([
                'name' => 'State Pharmaceuticals Corporation', 'phone' => '+94 11 000 0002', 'address' => '75 Sir Baron Jayathilaka Mawatha, Colombo 1',
                'payment_terms_days' => 45,
            ], $owner);

            $panadol = $item('Panadol 500 mg Tablets');
            $amoxil = $item('Amoxil 250 mg Capsules');
            $insulin = $item('Insulatard 100 IU/ml Vial');

            // An order to Hemas, sent and partly delivered: 3 of 5 boxes, all the strips (+2 free).
            $order = $orders->send($orders->create($hemas, $general, [
                new PurchaseLine($panadol->getKey(), 5, $unit($panadol, 'Box'), 95_000),
                new PurchaseLine($amoxil->getKey(), 20, $unit($amoxil, 'Strip'), 16_500),
            ], $owner, now($shop->timezone)->addDays(3)->toDateString(), 'Deliver before noon, please.'), $owner);
            [$boxes, $strips] = $order->items->all();

            $receipts->post($receipts->saveDraft(null, new GoodsReceiptData(
                supplierId: (int) $hemas->getKey(),
                locationId: (int) $general->getKey(),
                lines: [
                    new ReceiptLine($panadol->getKey(), 3, $unit($panadol, 'Box'), unitCost: 95_000, batchNo: 'PN2412', expiry: $month(24), purchaseOrderItemId: (int) $boxes->getKey()),
                    new ReceiptLine($amoxil->getKey(), 20, $unit($amoxil, 'Strip'), freeQuantity: 2, unitCost: 16_500, batchNo: 'AM1290', expiry: $month(18), purchaseOrderItemId: (int) $strips->getKey()),
                ],
                supplierInvoiceNo: 'HPL-55210',
                supplierInvoiceDate: now($shop->timezone)->toDateString(),
                invoiceDiscount: 5_000,
                purchaseOrderId: (int) $order->getKey(),
            ), $owner), $owner);

            // A delivery for Doctor's Medicines without an order, left as a draft to check and post.
            $receipts->saveDraft(null, new GoodsReceiptData(
                supplierId: (int) $spc->getKey(),
                locationId: (int) $doctors->getKey(),
                lines: [new ReceiptLine($insulin->getKey(), 5, unitCost: 380_000, batchNo: 'IN7801', expiry: $month(10))],
                supplierInvoiceNo: 'SPC-90817',
            ), $owner);

            // The expired Amoxil found on the shelf goes back to Hemas.
            $expired = StockBatch::query()->where('product_variant_id', $amoxil->getKey())->where('batch_no', 'AM0921')->firstOrFail();
            $returns->create($hemas, $general, SupplierReturnReason::Expired, [new SupplierReturnLine($amoxil->getKey(), 20, batchId: (int) $expired->getKey())], $owner, 'Collected by the Hemas rep.');

            // An older Hemas invoice, now past its 30 days.
            $day = fn (int $daysAgo): string => now($shop->timezone)->subDays($daysAgo)->toDateString();
            $receipts->post($receipts->saveDraft(null, new GoodsReceiptData(
                supplierId: (int) $hemas->getKey(),
                locationId: (int) $general->getKey(),
                lines: [new ReceiptLine($panadol->getKey(), 4, $unit($panadol, 'Box'), unitCost: 95_000, batchNo: 'PN2398', expiry: $month(20))],
                receivedOn: $day(48),
                supplierInvoiceNo: 'HPL-54980',
                supplierInvoiceDate: $day(50),
            ), $owner), $owner);

            // Paid oldest due first: HPL-54980 in part; the rest of what Hemas is owed is not yet due.
            $suppliers->recordPayment($hemas, 200_000, SupplierPaymentMethod::BankTransfer, $owner, reference: 'TRF 88213');

            // What was owed to SPC from before the system (go-live): a lump sum from the old books, due 45 days
            // (SPC's terms) after go-live, and an unpaid invoice from its statement, due this week.
            $suppliers->recordOpeningBalance($spc, 1_250_000, $owner, $day(14), 'From the old books: SPC statement at go-live.');
            $suppliers->recordOpeningBalance($spc, 340_000, $owner, $day(14), 'On the SPC statement at go-live.', 'SPC-88012', $day(42));
        }));
    }
}
