<?php

namespace App\Filament\Resources\StockCounts\Pages;

use App\Exceptions\PosException;
use App\Filament\Resources\StockCounts\StockCountResource;
use App\Models\Location;
use App\Models\User;
use App\Services\StockCountService;
use App\Support\CurrentShop;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

/** Starting a count puts the location's items (or a category or shelf of them) on a sheet. */
class CreateStockCount extends CreateRecord
{
    protected static string $resource = StockCountResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string|Htmlable
    {
        return 'Start a stock count';
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Count started: print the sheet or count on screen';
    }

    protected function getRedirectUrl(): string
    {
        return StockCountResource::getUrl('count', ['record' => $this->getRecord()]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $location = filled($data['location_id'] ?? null)
            ? Location::query()->findOrFail((int) $data['location_id'])
            : app(CurrentShop::class)->getOrFail()->defaultLocation();

        try {
            return app(StockCountService::class)->start(
                $location,
                $user,
                (bool) ($data['blind'] ?? false),
                filled($data['category_id'] ?? null) ? (int) $data['category_id'] : null,
                $data['bin'] ?? null,
                $data['note'] ?? null,
            );
        } catch (PosException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
