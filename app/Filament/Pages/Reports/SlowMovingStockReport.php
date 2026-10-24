<?php

namespace App\Filament\Pages\Reports;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Livewire\Attributes\Url;

/**
 * Slow-moving and dead stock (docs/PHARMACY.md §9, branch 11): what each
 * location holds with when it last sold there and how much sold in the
 * last N days (90 by default), valued at the location's average cost.
 * Dead stock: no sale for N days, held for longer than that.
 */
class SlowMovingStockReport extends ReportPage
{
    protected static ?string $title = 'Slow-moving and dead stock';

    protected static ?string $navigationLabel = 'Slow-moving stock';

    protected static ?int $navigationSort = 23;

    protected static bool $hasDateRange = false;

    protected static bool $hasLocationFilter = true;

    #[Url]
    public ?string $days = '90';

    #[Url]
    public ?string $dead = '1';

    public function windowDays(): int
    {
        return ctype_digit((string) $this->days) ? max(1, min((int) $this->days, 3650)) : 90;
    }

    private function deadOnly(): bool
    {
        return ! in_array($this->dead, ['0', 'false', ''], true);
    }

    protected function filterFields(): array
    {
        return [
            ...parent::filterFields(),
            TextInput::make('days')
                ->label('No sale in (days)')
                ->numeric()
                ->minValue(1)
                ->maxValue(3650)
                ->live(debounce: 500)
                ->afterStateUpdated(fn () => $this->resetPage()),
            Select::make('dead')
                ->label('Show')
                ->options(['1' => 'Dead stock only', '0' => 'Everything held'])
                ->selectablePlaceholder(false)
                ->live()
                ->afterStateUpdated(fn () => $this->resetPage()),
        ];
    }

    protected function documentFilters(): array
    {
        return [['Show', ($this->deadOnly() ? 'Dead stock: no sale' : 'Everything held, sales').' in the last '.$this->windowDays().' days']];
    }

    protected function reportColumns(): array
    {
        return [
            ...($this->multiLocation() ? [ReportColumn::text('location', 'Location')] : []),
            ReportColumn::text('sku', 'SKU'),
            ReportColumn::text('item', 'Item'),
            ReportColumn::quantity('on_hand', 'On hand', total: false),
            ReportColumn::text('last_sold', 'Last sold'),
            ReportColumn::integer('days_since', 'Days since', total: false),
            ReportColumn::quantity('sold', 'Sold in the period', total: false),
            ReportColumn::text('stocked_since', 'Stocked since'),
            ReportColumn::money('value', 'Value at average cost')->costOnly(),
        ];
    }

    protected function reportRows(): iterable
    {
        return $this->reports()->slowMoving($this->windowDays(), $this->locationId(), $this->deadOnly());
    }

    protected function documentNote(): ?string
    {
        return 'Quantities in base units. Sold in the period: the last '.$this->windowDays().' days, voided sales left out.';
    }
}
