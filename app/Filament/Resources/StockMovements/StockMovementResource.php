<?php

namespace App\Filament\Resources\StockMovements;

use App\Enums\StockMovementType;
use App\Filament\Resources\PermissionResource;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Support\ShopMoney;
use App\Models\ProductVariant;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Support\CurrentShop;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The stock ledger: read-only history of every stock change. With
 * multi_location, by location too; with pharmacy, the batch of each movement
 * and a batch filter (the Batches screen links here).
 */
class StockMovementResource extends PermissionResource
{
    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Stock history';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        $canSeeCost = fn (): bool => static::user()?->can('cost.view') ?? false;
        $multiLocation = fn (): bool => app(CurrentShop::class)->getOrFail()->hasModule('multi_location');
        $pharmacy = fn (): bool => app(CurrentShop::class)->getOrFail()->hasModule('pharmacy');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['variant.product', 'user', 'reference', 'location', 'batch']))
            ->columns([
                TextColumn::make('occurred_at')->dateTime()->sortable(),
                TextColumn::make('variant.sku')->label('SKU')->description(fn (StockMovement $record): ?string => $record->variant->product->name ?? null),
                TextColumn::make('location.name')->label('Location')->visible($multiLocation),
                TextColumn::make('batch.batch_no')
                    ->label('Batch')
                    ->state(fn (StockMovement $record): ?string => $record->batch?->label())
                    ->placeholder('-')
                    ->visible($pharmacy),
                TextColumn::make('type')->badge()->formatStateUsing(fn (StockMovementType $state): string => $state->label()),
                TextColumn::make('quantity')
                    ->formatStateUsing(fn ($state): string => ((float) $state > 0 ? '+' : '').rtrim(rtrim(number_format((float) $state, 3), '0'), '.'))
                    ->color(fn ($state): string => (float) $state < 0 ? 'danger' : 'success'),
                TextColumn::make('balance_after')->label('Balance')
                    ->formatStateUsing(fn ($state): string => rtrim(rtrim(number_format((float) $state, 3), '0'), '.')),
                TextColumn::make('unit_cost')->label('Unit cost')
                    ->formatStateUsing(fn ($state): ?string => ShopMoney::format($state === null ? null : (int) $state))
                    ->visible($canSeeCost),
                TextColumn::make('reason_code')
                    ->label('Reason / note')
                    ->state(fn (StockMovement $record): ?string => implode(' — ', array_filter([$record->reasonLabel(), $record->note], fn ($part): bool => filled($part))) ?: null)
                    ->wrap()
                    ->placeholder('-'),
                TextColumn::make('user.name')->label('By')->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('variant')
                    ->label('Item')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => ProductVariant::query()
                        ->where('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', $search)
                        ->limit(50)
                        ->pluck('sku', 'id')
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ProductVariant::query()->whereKey($value)->value('sku'))
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('product_variant_id', $data['value'])
                        : $query),
                SelectFilter::make('location')
                    ->relationship('location', 'name')
                    ->label('Location')
                    ->visible($multiLocation),
                SelectFilter::make('batch')
                    ->label('Batch')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => StockBatch::query()
                        ->with('variant')
                        ->where('batch_no', 'like', '%'.strtoupper($search).'%')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (StockBatch $batch): array => [(int) $batch->getKey() => $batch->variant->sku.' '.$batch->label()])
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($batch = StockBatch::query()->with('variant')->find($value)) instanceof StockBatch
                        ? $batch->variant->sku.' '.$batch->label()
                        : null)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('stock_batch_id', $data['value'])
                        : $query)
                    ->visible($pharmacy),
                SelectFilter::make('type')
                    ->options(fn (): array => StockMovementType::options(app(CurrentShop::class)->getOrFail()->hasModule('purchasing'))),
            ])
            ->defaultSort('occurred_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockMovements::route('/'),
        ];
    }
}
