<?php

namespace App\Filament\Pages\Reports;

use App\Services\ExpiryService;
use App\Support\CurrentShop;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Livewire\Attributes\Url;

/**
 * The expiry report (docs/PHARMACY.md §9, branch 11; pharmacy): batches
 * holding stock that expire within N days (the shop's warning window by
 * default) or have expired, by Stock → Expiry's rules, each at its batch
 * cost. Never the unallocated batch.
 */
class ExpiryReport extends ReportPage
{
    protected static ?string $title = 'Expiry';

    protected static ?int $navigationSort = 22;

    protected static bool $hasDateRange = false;

    protected static bool $hasLocationFilter = true;

    #[Url]
    public ?string $list = ExpiryService::SOON;

    #[Url]
    public ?string $days = null;

    protected static function isAvailable(): bool
    {
        return app(CurrentShop::class)->get()?->hasModule('pharmacy') ?? false;
    }

    private function listKey(): string
    {
        return $this->list === ExpiryService::EXPIRED ? ExpiryService::EXPIRED : ExpiryService::SOON;
    }

    public function windowDays(): int
    {
        $days = ctype_digit((string) $this->days) ? (int) $this->days : app(ExpiryService::class)->warningDays();

        return max(1, min($days, 3650));
    }

    protected function filterFields(): array
    {
        return [
            ...parent::filterFields(),
            Select::make('list')
                ->label('Show')
                ->options([ExpiryService::SOON => 'Expiring within N days', ExpiryService::EXPIRED => 'Expired'])
                ->selectablePlaceholder(false)
                ->live()
                ->afterStateUpdated(fn () => $this->resetPage()),
            TextInput::make('days')
                ->label('N days')
                ->numeric()
                ->minValue(1)
                ->maxValue(3650)
                ->placeholder((string) app(ExpiryService::class)->warningDays())
                ->visible(fn (): bool => $this->listKey() === ExpiryService::SOON)
                ->live(debounce: 500)
                ->afterStateUpdated(fn () => $this->resetPage()),
        ];
    }

    protected function documentFilters(): array
    {
        return [['Show', $this->listKey() === ExpiryService::EXPIRED ? 'Expired' : "Expiring within {$this->windowDays()} days"]];
    }

    protected function reportColumns(): array
    {
        return [
            ...($this->multiLocation() ? [ReportColumn::text('location', 'Location')] : []),
            ReportColumn::text('sku', 'SKU'),
            ReportColumn::text('item', 'Medicine'),
            ReportColumn::text('batch', 'Batch'),
            ReportColumn::text('expiry', 'Expiry'),
            ReportColumn::integer('days_left', 'Days left', total: false),
            ReportColumn::quantity('quantity', 'On hand (base units)', total: false),
            ReportColumn::money('value', 'Value at batch cost')->costOnly(),
        ];
    }

    protected function reportRows(): iterable
    {
        return $this->reports()->expiry($this->listKey(), $this->listKey() === ExpiryService::SOON ? $this->windowDays() : null, $this->locationId());
    }
}
