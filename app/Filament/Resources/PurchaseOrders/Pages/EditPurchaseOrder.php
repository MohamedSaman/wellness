<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Exceptions\PosException;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\PurchasingForm;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/** Only drafts are edited (PurchaseOrderPolicy::update); the lines are replaced. */
class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getRedirectUrl(): string
    {
        return PurchaseOrderResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $order = $this->getRecord();
        abort_unless($order instanceof PurchaseOrder, 404);

        return [
            ...$data,
            'expected_on' => $order->expected_on?->toDateString(),
            'lines' => $order->items()->get()->map(fn (PurchaseOrderItem $item): array => [
                'variant_id' => $item->product_variant_id,
                'unit_id' => $item->variant_unit_id,
                'quantity' => (float) $item->quantity,
                'unit_cost' => PurchasingForm::toInput($item->unit_cost),
            ])->all(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $record instanceof PurchaseOrder, 403);

        try {
            return app(PurchaseOrderService::class)->update(
                $record,
                Supplier::query()->findOrFail((int) $data['supplier_id']),
                PurchasingForm::location($data['location_id'] ?? null),
                PurchaseOrderResource::linesFrom($data),
                $user,
                $data['expected_on'] ?? null,
                $data['notes'] ?? null,
            );
        } catch (PosException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
