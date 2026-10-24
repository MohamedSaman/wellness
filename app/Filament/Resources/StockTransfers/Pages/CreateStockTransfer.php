<?php

namespace App\Filament\Resources\StockTransfers\Pages;

use App\Exceptions\PosException;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Models\Location;
use App\Models\User;
use App\Services\Data\TransferLine;
use App\Services\StockTransferService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateStockTransfer extends CreateRecord
{
    protected static string $resource = StockTransferResource::class;

    protected static bool $canCreateAnother = false;

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Transfer posted';
    }

    protected function getRedirectUrl(): string
    {
        return StockTransferResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $lines = array_values(array_map(fn (array $line): TransferLine => new TransferLine(
            (int) $line['variant_id'],
            (float) $line['quantity'],
            filled($line['unit_id'] ?? null) ? (int) $line['unit_id'] : null,
            filled($line['batch_id'] ?? null) ? (int) $line['batch_id'] : null,
        ), $data['lines'] ?? []));

        try {
            return app(StockTransferService::class)->create(
                Location::query()->findOrFail((int) $data['from_location_id']),
                Location::query()->findOrFail((int) $data['to_location_id']),
                $lines,
                $user,
                $data['note'] ?? null,
            );
        } catch (PosException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
