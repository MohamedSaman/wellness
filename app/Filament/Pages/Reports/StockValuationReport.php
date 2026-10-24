<?php

namespace App\Filament\Pages\Reports;

use Filament\Forms\Components\Select;
use Livewire\Attributes\Url;

/**
 * Stock on hand right now, valued at selling price (and at cost with cost.view),
 * each location's stock at its own weighted average cost; by category, by
 * location or by item; one location with multi_location. Stock by batch
 * values batches at their own cost (pharmacy).
 */
class StockValuationReport extends ReportPage
{
    protected static ?string $title = 'Stock valuation';

    protected static ?int $navigationSort = 8;

    protected static bool $hasDateRange = false;

    protected static bool $hasLocationFilter = true;

    /** category | location | item */
    #[Url]
    public ?string $by = 'category';

    /** @return 'category'|'location'|'item' */
    private function groupBy(): string
    {
        return match ($this->by) {
            'location' => $this->multiLocation() ? 'location' : 'category',
            'item' => 'item',
            default => 'category',
        };
    }

    protected function filterFields(): array
    {
        return [
            ...parent::filterFields(),
            Select::make('by')
                ->label('Group by')
                ->options(['category' => 'Category', ...($this->multiLocation() ? ['location' => 'Location'] : []), 'item' => 'Item'])
                ->selectablePlaceholder(false)
                ->live(),
        ];
    }

    protected function documentFilters(): array
    {
        return [['Grouped by', ucfirst($this->groupBy())]];
    }

    protected function reportColumns(): array
    {
        return [
            ...match ($this->groupBy()) {
                'location' => [ReportColumn::text('location', 'Location')],
                'item' => [ReportColumn::text('sku', 'SKU'), ReportColumn::text('item', 'Item')],
                default => [ReportColumn::text('category', 'Category')],
            },
            ReportColumn::quantity('quantity', 'On hand'),
            ReportColumn::money('retail_value', 'Value at selling price'),
            ReportColumn::money('cost_value', 'Value at average cost')->costOnly(),
            ReportColumn::money('last_cost_value', 'Value at last cost')->costOnly(),
        ];
    }

    protected function reportRows(): iterable
    {
        return $this->reports()->stockValuation($this->canSeeCost(), $this->locationId(), $this->groupBy());
    }
}
