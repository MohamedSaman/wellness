<?php

namespace App\Filament\Pages\Reports;

use App\Models\Location;
use App\Support\CurrentShop;
use Filament\Forms\Components\Select;
use Livewire\Attributes\Url;

/**
 * Stock moved between locations (docs/PHARMACY.md §9, branch 11;
 * multi_location): per route (General Pharmacy → Doctor's Medicines) and
 * item, the transfers, the quantity and its value at the source's average.
 */
class TransfersReport extends ReportPage
{
    protected static ?string $title = 'Transfers between locations';

    protected static ?string $navigationLabel = 'Transfers';

    protected static ?int $navigationSort = 25;

    #[Url]
    public ?string $fromLocation = null;

    #[Url]
    public ?string $toLocation = null;

    protected static function isAvailable(): bool
    {
        return app(CurrentShop::class)->get()?->hasModule('multi_location') ?? false;
    }

    private function locationOf(?string $value): ?int
    {
        return ctype_digit((string) $value) && Location::query()->whereKey((int) $value)->exists() ? (int) $value : null;
    }

    protected function filterFields(): array
    {
        $options = fn (): array => collect(Location::options())->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])->all();

        return [
            ...parent::filterFields(),
            Select::make('fromLocation')->label('From')->options($options)->placeholder('Any location')->live(),
            Select::make('toLocation')->label('To')->options($options)->placeholder('Any location')->live(),
        ];
    }

    protected function documentFilters(): array
    {
        $name = fn (?int $id): string => $id === null ? 'Any location' : (string) Location::query()->whereKey($id)->value('name');

        return [['From', $name($this->locationOf($this->fromLocation))], ['To', $name($this->locationOf($this->toLocation))]];
    }

    protected function reportColumns(): array
    {
        return [
            ReportColumn::text('route', 'Route'),
            ReportColumn::text('sku', 'SKU'),
            ReportColumn::text('item', 'Item'),
            ReportColumn::integer('transfers', 'Transfers', total: false),
            ReportColumn::quantity('quantity', 'Quantity (base units)', total: false),
            ReportColumn::text('packs', 'In packs'),
            ReportColumn::money('value', 'Value at cost')->costOnly(),
        ];
    }

    protected function reportRows(): iterable
    {
        return $this->reports()->transfers($this->period(), $this->locationOf($this->fromLocation), $this->locationOf($this->toLocation));
    }
}
