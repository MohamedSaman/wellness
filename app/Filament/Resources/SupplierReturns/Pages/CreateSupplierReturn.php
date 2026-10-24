<?php

namespace App\Filament\Resources\SupplierReturns\Pages;

use App\Enums\GoodsReceiptStatus;
use App\Enums\SupplierReturnReason;
use App\Exceptions\PosException;
use App\Filament\Resources\SupplierReturns\SupplierReturnResource;
use App\Filament\Support\PurchasingForm;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\User;
use App\Services\BatchService;
use App\Services\SupplierReturnService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Saving posts the return: the stock leaves the batches picked.
 * ?batches[]=… (the Expiry screen's "Return to supplier") fills it in with
 * those batches, all they hold, from their location, the supplier that
 * delivered them when it is one supplier, and expired or near expiry.
 */
class CreateSupplierReturn extends CreateRecord
{
    protected static string $resource = SupplierReturnResource::class;

    protected static bool $canCreateAnother = false;

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Return posted: the stock has left';
    }

    protected function getRedirectUrl(): string
    {
        return SupplierReturnResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function fillForm(): void
    {
        $ids = array_values(array_filter(array_map('intval', (array) request()->query('batches', []))));
        $batches = $ids === [] ? collect() : StockBatch::query()->with('variant')->whereKey($ids)->where('is_unallocated', false)->where('quantity', '>', 0)->fefo()->get();

        if ($batches->isEmpty() || $batches->pluck('location_id')->unique()->count() > 1) {
            parent::fillForm();

            return;
        }

        $today = app(BatchService::class)->today();
        $suppliers = GoodsReceiptItem::query()
            ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_items.goods_receipt_id')
            ->where('goods_receipts.status', GoodsReceiptStatus::Posted->value)
            ->where(fn ($query) => $batches->each(fn (StockBatch $batch) => $query->orWhere(fn ($line) => $line
                ->where('goods_receipt_items.product_variant_id', $batch->product_variant_id)
                ->where('goods_receipt_items.batch_no', $batch->batch_no))))
            ->distinct()
            ->pluck('goods_receipts.supplier_id');

        $this->form->fill([
            'returned_on' => now()->toDateString(),
            'location_id' => (int) $batches->first()->location_id,
            'supplier_id' => $suppliers->count() === 1 ? (int) $suppliers->first() : null,
            'reason' => $batches->every(fn (StockBatch $batch): bool => $batch->isExpiredOn($today))
                ? SupplierReturnReason::Expired->value
                : SupplierReturnReason::NearExpiry->value,
            'lines' => $batches->map(fn (StockBatch $batch): array => [
                'variant_id' => (int) $batch->product_variant_id,
                'batch_id' => (int) $batch->getKey(),
                'unit_id' => null,
                'quantity' => (float) $batch->quantity,
            ])->values()->all(),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            return app(SupplierReturnService::class)->create(
                Supplier::query()->findOrFail((int) $data['supplier_id']),
                PurchasingForm::location($data['location_id'] ?? null),
                SupplierReturnReason::from((string) $data['reason']),
                SupplierReturnResource::linesFrom($data),
                $user,
                $data['notes'] ?? null,
                $data['credit_note_no'] ?? null,
                $data['returned_on'] ?? null,
                filled($data['goods_receipt_id'] ?? null) ? GoodsReceipt::query()->findOrFail((int) $data['goods_receipt_id']) : null,
            );
        } catch (PosException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
