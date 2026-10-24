<?php

namespace App\Filament\Resources\GoodsReceipts\Pages;

use App\Exceptions\PosException;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Support\PurchasingForm;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\GoodsReceiptService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * A new goods received note, saved as a draft. Opened from a purchase
 * order (?order=uuid), it starts with the order's supplier, location and
 * what is still to come.
 */
class CreateGoodsReceipt extends CreateRecord
{
    protected static string $resource = GoodsReceiptResource::class;

    protected static bool $canCreateAnother = false;

    protected function fillForm(): void
    {
        $uuid = request()->query('order');
        $order = is_string($uuid) ? PurchaseOrder::query()->where('uuid', $uuid)->first() : null;

        if ($order === null || ! $order->status->isOpen()) {
            parent::fillForm();

            return;
        }

        $this->form->fill([
            'purchase_order_id' => $order->getKey(),
            'purchase_order' => $order->po_number,
            'supplier_id' => $order->supplier_id,
            'location_id' => $order->location_id,
            'received_on' => now()->toDateString(),
            'lines' => array_map(fn (array $line): array => [
                'purchase_order_item_id' => $line['purchase_order_item_id'],
                'variant_id' => $line['variant_id'],
                'unit_id' => $line['unit_id'],
                'quantity' => $line['quantity'],
                'unit_cost' => PurchasingForm::toInput($line['unit_cost']),
            ], app(GoodsReceiptService::class)->outstandingLines($order)),
        ]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Draft saved: check it, then post it to bring the stock in';
    }

    protected function getRedirectUrl(): string
    {
        return GoodsReceiptResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            return app(GoodsReceiptService::class)->saveDraft(null, GoodsReceiptResource::dataFrom($data), $user);
        } catch (PosException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
