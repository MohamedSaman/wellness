<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Support\ShopMoney;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\CurrentShop;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;

/**
 * Price changes at the till (docs/PHARMACY.md, branch 14): each bill line
 * charged a typed price other than its list price, newest first, from the
 * sale lines themselves (ReportService::priceChanges()). Filters: the
 * shop's days, an item, the cashier who typed the price and the counter.
 * The total is the lines' difference from the list price (+ above, − below).
 * Exports: CSV, Excel, PDF and print, like every report. Pharmacy shops.
 */
class PriceChangesReport extends ReportPage
{
    protected static ?string $title = 'Price changes at till';

    protected static ?int $navigationSort = 28;

    protected static bool $hasLocationFilter = true;

    protected static bool $isPaginated = true;

    /** Newest first: the order is the point. */
    protected static bool $isSortable = false;

    #[Url]
    public ?string $item = null;

    #[Url]
    public ?string $cashier = null;

    protected static function isAvailable(): bool
    {
        return app(CurrentShop::class)->get()?->hasModule('pharmacy') ?? false;
    }

    protected function locationLabel(): string
    {
        return 'Counter';
    }

    /** @return array{variant_id: int|null, cashier_id: int|null, location_id: int|null} */
    public function changeFilters(): array
    {
        return [
            'variant_id' => ctype_digit((string) $this->item) ? (int) $this->item : null,
            'cashier_id' => ctype_digit((string) $this->cashier) ? (int) $this->cashier : null,
            'location_id' => $this->locationId(),
        ];
    }

    protected function reportColumns(): array
    {
        return [
            ReportColumn::text('when', 'Date'),
            ReportColumn::text('invoice', 'Bill'),
            ReportColumn::text('till', 'Till'),
            ...($this->multiLocation() ? [ReportColumn::text('location', 'Counter')] : []),
            ReportColumn::text('item', 'Item'),
            ReportColumn::text('sku', 'SKU')->toggleable(hiddenByDefault: true),
            ReportColumn::quantity('quantity', 'Quantity', total: false),
            ReportColumn::money('list_price', 'List price', total: false),
            ReportColumn::money('charged_price', 'Charged price', total: false),
            ReportColumn::money('difference', 'Difference', total: false),
            ReportColumn::money('line_difference', 'Line difference', total: false),
            ReportColumn::text('cashier', 'Cashier'),
        ];
    }

    protected function filterFields(): array
    {
        $changed = fn () => $this->filtersUpdated();

        return [
            ...parent::filterFields(),
            Select::make('item')
                ->label('Item')
                ->placeholder('Every item')
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => $this->items($search))
                ->getOptionLabelUsing(fn (?string $value): ?string => $this->items(null, (int) $value)[(int) $value] ?? null)
                ->live()
                ->afterStateUpdated($changed),
            Select::make('cashier')
                ->label('Cashier')
                ->placeholder('Every cashier')
                ->options(fn (): array => $this->cashiers())
                ->live()
                ->afterStateUpdated($changed),
        ];
    }

    /** @return array<int, string> variant id => item, items whose price may be changed at the till first, by name */
    private function items(?string $search, ?int $id = null): array
    {
        return ProductVariant::query()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->when($id !== null, fn ($query) => $query->where('product_variants.id', $id))
            ->when($search !== null, fn ($query) => $query->where(fn ($match) => $match
                ->where('products.name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('product_variants.sku', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->orderByDesc('products.allow_price_override')
            ->orderBy('products.name')
            ->limit(50)
            ->get(['product_variants.id', 'products.name', 'product_variants.name as variant'])
            ->mapWithKeys(fn ($variant): array => [(int) $variant->id => trim($variant->name.' '.($variant->variant ?? ''))])
            ->all();
    }

    /** @return array<string, string> */
    private function cashiers(): array
    {
        return User::query()
            ->where('shop_id', app(CurrentShop::class)->getOrFail()->getKey())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])
            ->all();
    }

    protected function documentFilters(): array
    {
        $filters = $this->changeFilters();

        return [
            ['Item', $filters['variant_id'] === null ? 'Every item' : ($this->items(null, $filters['variant_id'])[$filters['variant_id']] ?? '')],
            ['Cashier', $filters['cashier_id'] === null ? 'Every cashier' : ($this->cashiers()[(string) $filters['cashier_id']] ?? '')],
        ];
    }

    protected function configureColumn(ReportColumn $column, TextColumn $text): TextColumn
    {
        return match ($column->key) {
            'when' => $text->extraAttributes(['style' => 'white-space: nowrap']),
            'invoice' => $text
                ->url(fn (array $record): ?string => isset($record['sale_uuid']) ? SaleResource::getUrl('view', ['record' => $record['sale_uuid']]) : null)
                ->color('primary')
                ->extraAttributes(['style' => 'white-space: nowrap']),
            'item' => $text->wrap()->extraAttributes(['style' => 'min-width: 10rem']),
            'difference', 'line_difference' => $text->color(fn ($state): ?string => $state === null ? null : ((int) $state < 0 ? 'danger' : null)),
            default => $text,
        };
    }

    protected function tableRows(int $page, int $perPage, ?string $sortColumn, ?string $sortDirection): array|LengthAwarePaginator
    {
        return $this->reports()->priceChanges($this->period(), $this->changeFilters(), $page, $perPage);
    }

    protected function reportRows(): iterable
    {
        return $this->reports()->priceChangeRows($this->period(), $this->changeFilters());
    }

    protected function tableFooter(): ?View
    {
        $totals = $this->reports()->priceChangeTotals($this->period(), $this->changeFilters());

        return view('filament.reports.price-change-totals', [
            'count' => $totals['count'],
            'difference' => ShopMoney::format($totals['difference']),
        ]);
    }

    protected function csvTotalRow(): ?array
    {
        return ['when' => 'Total', 'line_difference' => $this->reports()->priceChangeTotals($this->period(), $this->changeFilters())['difference']];
    }

    protected function documentNote(): ?string
    {
        return 'Prices per unit sold. Difference = charged price less list price (below the list price is negative); line difference = difference × quantity. Discounts are not included.';
    }
}
