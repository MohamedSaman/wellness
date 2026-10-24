<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Exceptions\PosException;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\PurchasingForm;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected static bool $canCreateAnother = false;

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Order saved as a draft';
    }

    protected function getRedirectUrl(): string
    {
        return PurchaseOrderResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            return app(PurchaseOrderService::class)->create(
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
