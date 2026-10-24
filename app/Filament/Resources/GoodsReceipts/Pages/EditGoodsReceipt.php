<?php

namespace App\Filament\Resources\GoodsReceipts\Pages;

use App\Exceptions\PosException;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Support\PurchasingForm;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\User;
use App\Services\GoodsReceiptService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/** Only drafts are edited (GoodsReceiptPolicy::update); the lines are replaced. */
class EditGoodsReceipt extends EditRecord
{
    protected static string $resource = GoodsReceiptResource::class;

    protected function getRedirectUrl(): string
    {
        return GoodsReceiptResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Draft saved';
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $receipt = $this->getRecord();
        abort_unless($receipt instanceof GoodsReceipt, 404);
        $receipt->loadMissing('purchaseOrder');

        return [
            ...$data,
            'received_on' => $receipt->received_on->toDateString(),
            'supplier_invoice_date' => $receipt->supplier_invoice_date?->toDateString(),
            'purchase_order' => $receipt->purchaseOrder?->po_number,
            'invoice_discount' => $receipt->invoice_discount === 0 ? null : PurchasingForm::toInput($receipt->invoice_discount),
            'invoice_tax' => $receipt->invoice_tax === 0 ? null : PurchasingForm::toInput($receipt->invoice_tax),
            'lines' => $receipt->items()->get()->map(fn (GoodsReceiptItem $item): array => GoodsReceiptResource::formLine($item))->all(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $record instanceof GoodsReceipt, 403);

        try {
            return app(GoodsReceiptService::class)->saveDraft($record, GoodsReceiptResource::dataFrom($data), $user);
        } catch (PosException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
