<?php

namespace App\Filament\Resources\StockRequests\Pages;

use App\Exceptions\PosException;
use App\Filament\Resources\StockRequests\StockRequestResource;
use App\Models\Location;
use App\Models\User;
use App\Services\Data\RequestLine;
use App\Services\ReorderService;
use App\Services\StockRequestService;
use App\Support\CurrentShop;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * A new stock request. ?suggest={location id} fills it in from that
 * location's low stock, up to par (ReorderService, "Suggest request" on
 * the Reorder screen), to check before sending.
 */
class CreateStockRequest extends CreateRecord
{
    protected static string $resource = StockRequestResource::class;

    protected static bool $canCreateAnother = false;

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Request sent';
    }

    protected function getRedirectUrl(): string
    {
        return StockRequestResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function fillForm(): void
    {
        $locationId = request()->query('suggest');

        if (! is_string($locationId) || ! ctype_digit($locationId) || ! array_key_exists((int) $locationId, StockRequestResource::requestingLocations())) {
            parent::fillForm();

            return;
        }

        $location = Location::query()->findOrFail((int) $locationId);
        $lines = app(ReorderService::class)->requestLines($location);

        if ($lines === []) {
            Notification::make()->title("Nothing at {$location->name} is below its reorder level and short of its par level.")->warning()->send();
            parent::fillForm();

            return;
        }

        $shop = app(CurrentShop::class)->getOrFail();

        $this->form->fill([
            'to_location_id' => (int) $location->getKey(),
            'from_location_id' => (int) $shop->defaultLocation()->getKey() === (int) $location->getKey() ? null : (int) $shop->defaultLocation()->getKey(),
            'note' => 'Topping up to par levels',
            'lines' => array_map(fn (RequestLine $line): array => ['variant_id' => $line->variantId, 'unit_id' => null, 'quantity' => $line->quantity], $lines),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $lines = array_values(array_map(fn (array $line): RequestLine => new RequestLine(
            (int) $line['variant_id'],
            (float) $line['quantity'],
            filled($line['unit_id'] ?? null) ? (int) $line['unit_id'] : null,
        ), $data['lines'] ?? []));

        try {
            return app(StockRequestService::class)->create(
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
