<?php

namespace App\Services\Data;

/**
 * A goods received note as entered: who it is from, where it arrived, the
 * supplier's invoice, an optional discount and an optional tax (VAT) on
 * the whole invoice (minor units, each spread over the lines by value) and
 * the lines.
 */
final readonly class GoodsReceiptData
{
    /**
     * @param  list<ReceiptLine>  $lines
     */
    public function __construct(
        public int $supplierId,
        public int $locationId,
        public array $lines,
        public ?string $receivedOn = null,
        public ?string $supplierInvoiceNo = null,
        public ?string $supplierInvoiceDate = null,
        public int $invoiceDiscount = 0,
        public ?string $notes = null,
        public ?int $purchaseOrderId = null,
        public int $invoiceTax = 0,
    ) {}
}
