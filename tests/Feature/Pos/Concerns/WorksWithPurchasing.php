<?php

namespace Tests\Feature\Pos\Concerns;

use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Data\GoodsReceiptData;
use App\Services\Data\PurchaseLine;
use App\Services\Data\ReceiptLine;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseOrderService;
use App\Services\SupplierService;

/**
 * Helpers for purchasing tests (suppliers, purchase orders, goods received,
 * supplier returns), shared by the SQLite tests and the MySQL group.
 * Needs $owner and $general from the test case.
 */
trait WorksWithPurchasing
{
    private int $bills = 0;

    /** A posted GRN for one unit of $item worth $amount: an invoice owed to $supplier. */
    protected function bill(Supplier $supplier, ProductVariant $item, int $amount, ?string $invoiceNo = null, ?string $invoiceDate = null, ?string $receivedOn = null): GoodsReceipt
    {
        $this->bills++;

        return $this->postReceipt($supplier, [new ReceiptLine($item->getKey(), 1, unitCost: $amount, batchNo: "BILL{$this->bills}", expiry: '12/2027')], invoiceNo: $invoiceNo, invoiceDate: $invoiceDate, receivedOn: $receivedOn);
    }

    protected function supplier(string $name = 'Hemas Pharmaceuticals', array $data = []): Supplier
    {
        return app(SupplierService::class)->create(['name' => $name, 'payment_terms_days' => 30, ...$data], $this->owner);
    }

    /**
     * A draft goods received note.
     *
     * @param  list<ReceiptLine>  $lines
     */
    protected function draftReceipt(Supplier $supplier, array $lines, ?Location $at = null, int $invoiceDiscount = 0, ?string $invoiceNo = null, ?PurchaseOrder $order = null, ?User $user = null, int $invoiceTax = 0, ?string $invoiceDate = null, ?string $receivedOn = null): GoodsReceipt
    {
        return app(GoodsReceiptService::class)->saveDraft(null, new GoodsReceiptData(
            supplierId: (int) $supplier->getKey(),
            locationId: (int) ($at ?? $this->general)->getKey(),
            lines: $lines,
            receivedOn: $receivedOn,
            supplierInvoiceNo: $invoiceNo,
            supplierInvoiceDate: $invoiceDate,
            invoiceDiscount: $invoiceDiscount,
            purchaseOrderId: $order === null ? null : (int) $order->getKey(),
            invoiceTax: $invoiceTax,
        ), $user ?? $this->owner);
    }

    /**
     * A goods received note, saved and posted.
     *
     * @param  list<ReceiptLine>  $lines
     */
    protected function postReceipt(Supplier $supplier, array $lines, ?Location $at = null, int $invoiceDiscount = 0, ?string $invoiceNo = null, ?PurchaseOrder $order = null, ?User $user = null, int $invoiceTax = 0, ?string $invoiceDate = null, ?string $receivedOn = null): GoodsReceipt
    {
        $draft = $this->draftReceipt($supplier, $lines, $at, $invoiceDiscount, $invoiceNo, $order, $user, $invoiceTax, $invoiceDate, $receivedOn);

        return app(GoodsReceiptService::class)->post($draft, $user ?? $this->owner);
    }

    /**
     * A purchase order, sent to the supplier.
     *
     * @param  list<PurchaseLine>  $lines
     */
    protected function sentOrder(Supplier $supplier, array $lines, ?Location $at = null): PurchaseOrder
    {
        $orders = app(PurchaseOrderService::class);

        return $orders->send($orders->create($supplier, $at ?? $this->general, $lines, $this->owner), $this->owner);
    }
}
