<?php

namespace App\Filament\Pages\Reports;

use App\Enums\StockMovementType;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Support\ShopMoney;
use App\Models\User;
use App\Support\CurrentShop;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;

/**
 * Every stock movement in the period, filtered by type, product/SKU and user.
 * Paged in SQL; the exports stream the same filtered rows and on-screen
 * columns a thousand at a time (ReportService::stockMovementRows()). The footer shows the net quantity change for the whole
 * filtered range, and its value at cost for users with cost.view.
 */
class StockMovementsReport extends ReportPage
{
    protected static ?string $title = 'Stock movements';

    protected static ?int $navigationSort = 10;

    protected static bool $isPaginated = true;

    protected static bool $hasLocationFilter = true;

    /** @var list<string> */
    #[Url]
    public array $types = [];

    #[Url]
    public ?string $product = null;

    #[Url]
    public ?string $user = null;

    protected function reportColumns(): array
    {
        return [
            ReportColumn::text('when', 'When'),
            ReportColumn::text('sku', 'SKU'),
            ReportColumn::text('product', 'Product'),
            ...($this->multiLocation() ? [ReportColumn::text('location', 'Location')] : []),
            ...(app(CurrentShop::class)->getOrFail()->hasModule('pharmacy') ? [ReportColumn::text('batch', 'Batch')] : []),
            ReportColumn::text('type', 'Type'),
            ReportColumn::quantity('quantity', 'Quantity', total: false),
            ReportColumn::quantity('balance', 'Balance', total: false)->toggleable(),
            ReportColumn::text('reference', 'Reference'),
            ReportColumn::money('unit_cost', 'Unit cost', total: false)->costOnly()->toggleable(hiddenByDefault: true),
            ReportColumn::money('value', 'Value at cost', total: false)->costOnly()->toggleable(hiddenByDefault: true),
            ReportColumn::text('reason', 'Reason / note')->toggleable(hiddenByDefault: true),
            ReportColumn::text('by_user', 'By')->toggleable(),
        ];
    }

    /** @return array{types: list<string>, product: string|null, user_id: int|null, location_id: int|null} */
    public function movementFilters(): array
    {
        return [
            'types' => array_map('strval', $this->types),
            'product' => filled($this->product) ? (string) $this->product : null,
            'user_id' => ctype_digit((string) $this->user) ? (int) $this->user : null,
            'location_id' => $this->locationId(),
        ];
    }

    protected function filterFields(): array
    {
        $reset = fn () => $this->resetPage();

        return [
            ...parent::filterFields(),
            Select::make('types')
                ->label('Type')
                ->multiple()
                ->options(fn (): array => StockMovementType::options(app(CurrentShop::class)->getOrFail()->hasModule('purchasing')))
                ->placeholder('All types')
                ->live()
                ->afterStateUpdated($reset),
            TextInput::make('product')
                ->label('Product or SKU')
                ->placeholder('Name, SKU, part no. or barcode')
                ->live(debounce: 400)
                ->afterStateUpdated($reset),
            Select::make('user')
                ->label('User')
                ->options(fn (): array => User::query()
                    ->where('shop_id', app(CurrentShop::class)->getOrFail()->getKey())
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])
                    ->all())
                ->placeholder('Anyone')
                ->live()
                ->afterStateUpdated($reset),
        ];
    }

    protected function configureColumn(ReportColumn $column, TextColumn $text): TextColumn
    {
        return match ($column->key) {
            'when' => $text->extraAttributes(['style' => 'white-space: nowrap']),
            // Long SKUs are cut with the full value in a tooltip, so the table stays narrow.
            'sku' => $text->limit(14)->tooltip(fn (array $record): ?string => mb_strlen((string) ($record['sku'] ?? '')) > 14 ? $record['sku'] : null),
            'product' => $text->wrap()->extraAttributes(['style' => 'min-width: 8rem']),
            'quantity' => $text
                ->formatStateUsing(fn ($state): string => ((float) $state > 0 ? '+' : '').self::quantity($state))
                ->color(fn ($state): string => (float) $state < 0 ? 'danger' : 'success'),
            'reference' => $text
                ->url(fn (array $record): ?string => isset($record['reference_sale'])
                    ? SaleResource::getUrl('view', ['record' => $record['reference_sale']])
                    : null)
                ->color(fn (array $record): string => isset($record['reference_sale']) ? 'primary' : 'gray'),
            'reason' => $text->wrap()->extraAttributes(['style' => 'min-width: 14rem']),
            'by_user' => $text->limit(18)->tooltip(fn (array $record): ?string => $record['by_user'] ?? null),
            default => $text,
        };
    }

    protected function tableRows(int $page, int $perPage, ?string $sortColumn, ?string $sortDirection): array|LengthAwarePaginator
    {
        return $this->reports()->stockMovements($this->period(), $this->canSeeCost(), $page, $perPage, $this->movementFilters());
    }

    protected function reportRows(): iterable
    {
        $period = $this->period();

        foreach ($this->reports()->stockMovementRows($period, $this->canSeeCost(), $this->movementFilters()) as $row) {
            yield $this->reports()->presentMovement($row, $period);
        }
    }

    /** @return array{quantity: float, value: int|null, count: int} */
    public function totals(): array
    {
        return $this->reports()->stockMovementTotals($this->period(), $this->canSeeCost(), $this->movementFilters());
    }

    protected function tableFooter(): ?View
    {
        $totals = $this->totals();

        return view('filament.reports.movement-totals', [
            'count' => $totals['count'],
            'quantity' => ($totals['quantity'] > 0 ? '+' : '').self::quantity($totals['quantity']),
            'value' => $totals['value'] === null ? null : ShopMoney::format($totals['value']),
        ]);
    }

    protected function csvTotalRow(): ?array
    {
        $totals = $this->totals();

        return [
            'when' => 'Total',
            'quantity' => $totals['quantity'],
            'value' => $totals['value'],
        ];
    }
}
