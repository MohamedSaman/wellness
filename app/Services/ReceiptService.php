<?php

namespace App\Services;

use App\Enums\BillType;
use App\Enums\SaleStatus;
use App\Enums\TaxCategory;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemBatch;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Shift;
use App\Models\Shop;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\Receipts\EscPos;
use App\Services\Receipts\ReceiptLayout;
use App\Support\CurrentShop;
use App\Support\Quantity;
use App\Support\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Everything a printed receipt shows, for the first print and for reprints:
 * sale receipts, refund slips and void slips.
 *
 * The payload is plain data (money in minor units plus the currency settings),
 * built only from the sale's own rows (lines, batches, payments, returns as
 * recorded), never from the product as it is now: a reprint prints what was
 * sold. The till, its location and the people are read by name.
 *
 * A pharmacy shop prints the thermal layout (docs/PHARMACY.md §9, branch 9):
 * thermal() lays the payload out as fixed-width lines (ReceiptLayout) and
 * encodes them for an ESC/POS printer; the browser prints the same lines.
 * A retail shop's sale receipt is the HTML receipt it always had
 * (ReceiptView.vue); its refund and void slips use the thermal layout.
 *
 * @phpstan-type PrintOptions array{paperWidth: int, showBatch: bool, showExpiry: bool, showPrescriber: bool}
 * @phpstan-type ReceiptShop array{name: string, address: ?string, phone: ?string, taxNumber: ?string, logoUrl: ?string, header: ?string, footer: ?string, currency: string, decimals: int, receipt: PrintOptions}
 */
class ReceiptService
{
    public const REPRINT_EVENT = 'receipt.reprinted';

    /** How many sales the recent sales panel lists at most. */
    public const RECENT_LIMIT = 50;

    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly AuditObserver $audit,
    ) {}

    /** What a reprint entry reprinted: the sale's receipt, one of its refund slips, or its void slip. */
    public const DOCUMENTS = ['sale', 'refund', 'void'];

    /**
     * Records a reprint in the audit log. The entry is what the duplicate
     * receipt shows in its "Reprinted on ... by ..." line. A refund slip
     * names its return, and a void slip needs a voided sale.
     */
    public function recordReprint(Sale $sale, User $user, ?Device $till, string $document = 'sale', ?SaleReturn $return = null): AuditLog
    {
        if (! in_array($document, self::DOCUMENTS, true)
            || ($document === 'refund') !== ($return !== null)
            || ($return !== null && (int) $return->sale_id !== (int) $sale->getKey())
            || ($document === 'void' && $sale->status !== SaleStatus::Voided)) {
            throw new \InvalidArgumentException("Nothing to reprint as {$document} for sale {$sale->invoice_no}.");
        }

        return $this->audit->record(self::REPRINT_EVENT, $sale, [], [
            'sale_id' => $sale->getKey(),
            'invoice_no' => $sale->invoice_no,
            'device_id' => $till?->getKey(),
            'till' => $till?->code,
            ...($document === 'sale' ? [] : ['document' => $document]),
            ...($return === null ? [] : ['sale_return_id' => $return->getKey(), 'return_no' => $return->return_no]),
        ], (int) $user->getKey());
    }

    /** The document a reprint entry reprinted ('sale' for entries from before refund and void slips). */
    public static function reprintedDocument(AuditLog $entry): string
    {
        $document = $entry->new_values['document'] ?? 'sale';

        return in_array($document, self::DOCUMENTS, true) ? $document : 'sale';
    }

    public function currentShop(): Shop
    {
        return $this->currentShop->getOrFail();
    }

    /** Whether the shop prints the thermal layout for sale receipts (pharmacy); retail keeps its HTML receipt. */
    public function usesThermalReceipt(): bool
    {
        return $this->currentShop->getOrFail()->hasModule('pharmacy');
    }

    /**
     * A receipt payload laid out for the shop's paper: the lines the browser
     * prints, and the same as ESC/POS bytes (base64) for a thermal printer.
     *
     * @param  'sale'|'refund'|'void'  $document
     * @param  array<string, mixed>  $payload  sale(), refund() or voidSlip()
     * @param  ReceiptShop|null  $shop  shop(), or the shop as Receipt settings' preview has it
     * @return array{paperWidth: int, columns: int, lines: list<array{text: string, bold: bool, tall: bool}>, escpos: string}
     */
    public function thermal(string $document, array $payload, ?array $shop = null): array
    {
        $shop ??= $this->shop();
        $layout = new ReceiptLayout($shop);
        $lines = match ($document) {
            'sale' => $layout->sale($payload),
            'refund' => $layout->refund($payload),
            'void' => $layout->voidSlip($payload),
        };
        $paperWidth = (int) $shop['receipt']['paperWidth'];

        return [
            'paperWidth' => $paperWidth,
            'columns' => ReceiptLayout::columns($paperWidth),
            'lines' => $lines,
            'escpos' => base64_encode(EscPos::encode($lines, $this->logoRaster($paperWidth))),
        ];
    }

    private function logoRaster(int $paperWidth): ?string
    {
        $path = $this->currentShop->getOrFail()->logo_path;
        $disk = Storage::disk('public');

        return filled($path) && $disk->exists($path) ? EscPos::raster((string) $disk->get($path), $paperWidth) : null;
    }

    /** A reprint entry of this sale in the current shop, or null. Audit logs are not tenant-scoped, so the shop is checked here. */
    public function findReprint(Sale $sale, int $auditLogId): ?AuditLog
    {
        return AuditLog::query()
            ->with('user')
            ->whereKey($auditLogId)
            ->where('shop_id', $this->currentShop->id())
            ->where('event', self::REPRINT_EVENT)
            ->where('auditable_type', $sale->getMorphClass())
            ->where('auditable_id', $sale->getKey())
            ->first();
    }

    /**
     * This till's sales from its open shift and from today (shop time), newest
     * first, optionally narrowed to invoice numbers containing $search.
     *
     * @return Collection<int, Sale>
     */
    public function recentSales(Device $till, ?string $search = null): Collection
    {
        $shiftId = Shift::query()
            ->where('device_id', $till->getKey())
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->value('id');

        $todayStartsAt = ReportPeriod::today($this->currentShop->getOrFail())->startUtc();
        $search = trim((string) $search);

        return Sale::query()
            ->with('cashier')
            ->where('device_id', $till->getKey())
            ->where(fn (Builder $query) => $query
                ->where('sold_at', '>=', $todayStartsAt)
                ->when($shiftId !== null, fn (Builder $query) => $query->orWhere('shift_id', $shiftId)))
            ->when($search !== '', fn (Builder $query) => $query->where('invoice_no', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->latest('sold_at')
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    /**
     * The header and footer, the currency, and how the shop prints receipts
     * (`receipt`: paper width, and whether batch, expiry and prescriber are
     * printed under each medicine), read when printing.
     *
     * @return ReceiptShop
     */
    public function shop(): array
    {
        $shop = $this->currentShop->getOrFail();

        return [
            'name' => $shop->name,
            'address' => $shop->address,
            'phone' => $shop->phone,
            'taxNumber' => $shop->tax_number,
            'logoUrl' => $shop->logo_path ? Storage::disk('public')->url($shop->logo_path) : null,
            'header' => $shop->receipt_header,
            'footer' => $shop->receipt_footer,
            'currency' => (string) $shop->currency,
            'decimals' => (int) $shop->currency_decimals,
            'receipt' => self::printOptions($shop),
        ];
    }

    /**
     * How the shop prints receipts (docs/PHARMACY.md §9, branch 9): also sent
     * to the till for offline receipts (CatalogSyncService::shopSettings()).
     *
     * @return PrintOptions
     */
    public static function printOptions(Shop $shop): array
    {
        return [
            'paperWidth' => (int) $shop->setting('receipt_paper_width') === 58 ? 58 : 80,
            'showBatch' => (bool) $shop->setting('receipt_show_batch', true),
            'showExpiry' => (bool) $shop->setting('receipt_show_expiry', true),
            'showPrescriber' => (bool) $shop->setting('receipt_show_prescriber', false),
        ];
    }

    /**
     * The sale as printed. With $reprint, the receipt is marked as a duplicate
     * and says when and by whom it was reprinted.
     *
     * An OPD bill (docs/PHARMACY.md §9, branch 8) prints the consultation and
     * one Medicines amount: medicinesTotal is the sum of its medicine lines
     * (their shares of the typed amount), and opdReceiptMedicines (shop
     * setting, read at print time) says whether the medicines are listed by
     * name under it ('names') or left out ('totals'). A medicine line's own
     * share is never in the payload (unitPrice, discount and lineTotal are
     * null): an unpriced medicine's share is weighed by its cost, which a
     * cashier must not be able to work out (CheckoutService::quote()).
     * Admin screens show the shares.
     *
     * Every line is as sold (branch 9): its description, strength, units,
     * batches and expiry are the sale's own copies, so editing or renaming the
     * product, or correcting a batch, never changes a reprint.
     *
     * @return array<string, mixed>
     */
    public function sale(Sale $sale, ?AuditLog $reprint = null): array
    {
        $shop = $this->currentShop->getOrFail();
        $timezone = $shop->timezone;
        $sale->loadMissing(['items.batches', 'items.location', 'items.returnItems', 'payments', 'cashier', 'device', 'location']);
        $isOpd = $sale->bill_type === BillType::Opd;

        return [
            'invoiceNo' => $sale->invoice_no,
            'status' => $sale->status->value,
            'billType' => $sale->bill_type->value,
            'opdReceiptMedicines' => $this->opdReceiptMedicines($shop),
            'medicinesTotal' => $isOpd ? (int) $sale->items->where('is_opd_medicine', true)->sum('line_total') : null,
            'soldAt' => $sale->sold_at->timezone($timezone)->format('Y-m-d H:i'),
            'cashier' => $sale->cashier->name,
            'till' => $this->till($sale),
            'customerName' => $sale->customer_name,
            'customerPhone' => $sale->customer_phone,
            'subtotal' => $sale->subtotal,
            'discountTotal' => $sale->discount_total,
            'taxTotal' => $sale->tax_total,
            'taxRate' => (float) $sale->tax_rate,
            'pricesIncludeTax' => (bool) $sale->prices_include_tax,
            'grandTotal' => $sale->grand_total,
            'paidTotal' => $sale->paid_total,
            'changeDue' => $sale->change_due,
            'refundedTotal' => $sale->refunded_total,
            'items' => $sale->items->map(fn (SaleItem $item): array => [
                'description' => $item->description,
                'sku' => $item->sku,
                'strength' => $item->strength,
                'unitName' => $item->unit_name,
                'baseUnitName' => $item->base_unit_name,
                'location' => $item->location?->name,
                'quantity' => $this->quantity((float) $item->quantity),
                'returnedQuantity' => (float) $item->returned_quantity > 0 ? $this->quantity((float) $item->returned_quantity) : null,
                'returnedBaseQuantity' => (float) $item->returned_quantity > 0 ? $this->quantity((float) $item->returnItems->sum('base_quantity')) : null,
                ...($isOpd && $item->is_opd_medicine ? [
                    'unitPrice' => null,
                    'discount' => null,
                    'lineTotal' => null,
                ] : [
                    'unitPrice' => $item->unit_price,
                    'discount' => $item->line_discount + $item->sale_discount_share,
                    'lineTotal' => $item->line_total,
                ]),
                'taxExempt' => $item->tax_category === TaxCategory::Exempt,
                'isOpdMedicine' => $item->is_opd_medicine,
                'prescriber' => $item->prescriber,
                'prescriptionRef' => $item->prescription_ref,
                // Batch-tracked lines, as sold (snapshots on the line): a reprint never shows later corrections.
                ...($item->batches->isEmpty() ? [] : ['batches' => $item->batches->map(fn (SaleItemBatch $batch): array => [
                    'batchNo' => $batch->is_unallocated ? null : $batch->batch_no,
                    'expiry' => $batch->expiry_date?->format('m/Y'),
                    'quantity' => $this->quantity((float) $batch->quantity),
                ])->values()->all()]),
            ])->values()->all(),
            'payments' => $sale->payments->map(fn (Payment $payment): array => [
                'method' => $this->methodLabel($payment->method->value),
                'amount' => $payment->amount,
                'reference' => $payment->reference,
            ])->values()->all(),
            'reprint' => $this->reprintStamp($reprint),
        ];
    }

    /**
     * A voided sale's slip: the bill as rung up (sale()), plus when, by whom
     * and why it was voided.
     *
     * @return array<string, mixed>
     */
    public function voidSlip(Sale $sale, ?AuditLog $reprint = null): array
    {
        abort_unless($sale->status === SaleStatus::Voided, 404);

        $timezone = $this->currentShop->getOrFail()->timezone;
        $sale->loadMissing('voider');

        return [
            ...$this->sale($sale, $reprint),
            'void' => [
                'voidedAt' => $sale->voided_at?->timezone($timezone)->format('Y-m-d H:i'),
                'voidedBy' => $sale->voider->name ?? 'Unknown user',
                'reason' => (string) $sale->void_reason,
            ],
        ];
    }

    /**
     * A refund slip: the lines of one return as they came back (in the unit
     * the line was sold in when that is whole, else in base units: "10
     * Tablet" from a box), each line's refund, the refund total and how it
     * was paid back. On an OPD bill the medicines are one Medicines amount,
     * as on the bill: a medicine line's own refund is not in the payload.
     *
     * @return array<string, mixed>
     */
    public function refund(SaleReturn $return, ?AuditLog $reprint = null): array
    {
        $shop = $this->currentShop->getOrFail();
        $timezone = $shop->timezone;
        $return->loadMissing(['items.saleItem.location', 'user', 'sale.cashier', 'sale.device', 'sale.location']);
        $sale = $return->sale;
        $isOpd = $sale->bill_type === BillType::Opd;
        $medicines = $return->items->filter(fn (SaleReturnItem $line): bool => $isOpd && $line->saleItem->is_opd_medicine);

        return [
            'returnNo' => $return->return_no,
            'returnedAt' => $return->returned_at->timezone($timezone)->format('Y-m-d H:i'),
            'user' => $return->user->name ?? 'Unknown user',
            'reason' => $return->reason_code->label(),
            'note' => $return->note,
            'refundTotal' => $return->refund_total,
            'method' => $this->methodLabel($return->refund_method->value),
            'reference' => $return->refund_reference,
            'medicinesRefund' => $isOpd && $medicines->isNotEmpty() ? (int) $medicines->sum('refund_amount') : null,
            'sale' => [
                'invoiceNo' => $sale->invoice_no,
                'soldAt' => $sale->sold_at->timezone($timezone)->format('Y-m-d H:i'),
                'cashier' => $sale->cashier->name,
                'till' => $this->till($sale),
                'billType' => $sale->bill_type->value,
                'opdReceiptMedicines' => $this->opdReceiptMedicines($shop),
                'taxRate' => (float) $sale->tax_rate,
            ],
            'items' => $return->items->map(function (SaleReturnItem $line) use ($isOpd): array {
                $item = $line->saleItem;
                $inSoldUnits = Quantity::isWhole((float) $line->quantity) || $item->base_unit_name === null;
                $isMedicine = $isOpd && $item->is_opd_medicine;

                return [
                    'description' => $item->description,
                    'sku' => $item->sku,
                    'strength' => $item->strength,
                    // The pack is cut from the description only when the quantity is in it.
                    'unitName' => $inSoldUnits ? $item->unit_name : $item->base_unit_name,
                    'baseUnitName' => $item->base_unit_name,
                    'packName' => $item->unit_name,
                    'location' => $item->location?->name,
                    'quantity' => $this->quantity($inSoldUnits ? (float) $line->quantity : (float) $line->base_quantity),
                    'refund' => $isMedicine ? null : $line->refund_amount,
                    'restocked' => $line->restocked,
                    'taxExempt' => $item->tax_category === TaxCategory::Exempt,
                    'isOpdMedicine' => $isMedicine,
                    ...(blank($line->batches) ? [] : ['batches' => array_map(fn (array $batch): array => [
                        'batchNo' => $batch['batch_no'] === '' ? null : $batch['batch_no'],
                        'expiry' => filled($batch['expiry_date'] ?? null) ? date('m/Y', (int) strtotime((string) $batch['expiry_date'])) : null,
                        'quantity' => $this->quantity((float) $batch['quantity']),
                    ], $line->batches)]),
                ];
            })->values()->all(),
            'reprint' => $this->reprintStamp($reprint),
        ];
    }

    /**
     * @return array{code: string, name: string|null, location: string|null}|null
     */
    private function till(Sale $sale): ?array
    {
        return $sale->device === null ? null : [
            'code' => (string) $sale->device->code,
            'name' => $sale->device->name,
            'location' => $sale->location->name ?? null,
        ];
    }

    /** @return array{printedAt: string|null, printedBy: string}|null */
    private function reprintStamp(?AuditLog $reprint): ?array
    {
        return $reprint === null ? null : [
            'printedAt' => $reprint->created_at?->timezone($this->currentShop->getOrFail()->timezone)->format('Y-m-d H:i'),
            'printedBy' => $reprint->user->name ?? 'Unknown user',
        ];
    }

    private function opdReceiptMedicines(Shop $shop): string
    {
        return $shop->setting('opd_receipt_medicines') === 'totals' ? 'totals' : 'names';
    }

    private function methodLabel(string $method): string
    {
        return ucwords(str_replace('_', ' ', $method));
    }

    private function quantity(float $quantity): string
    {
        return rtrim(rtrim(number_format(Quantity::normalize($quantity), 3, '.', ''), '0'), '.');
    }
}
