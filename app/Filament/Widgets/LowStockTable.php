<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Widgets\Concerns\NeedsReportsPermission;
use App\Services\ReportService;
use App\Support\CurrentShop;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Variants at or below their reorder level, lowest first. Negative stock (sold offline) is red. */
class LowStockTable extends TableWidget
{
    use NeedsReportsPermission;

    protected static ?int $sort = 8;

    public function table(Table $table): Table
    {
        $reports = app(ReportService::class);
        $quantity = fn ($state): string => rtrim(rtrim(number_format((float) $state, 3), '0'), '.');

        return $table
            ->heading('Low stock')
            ->description(fn (): string => $reports->lowStockCount().' item(s) at or below their reorder level')
            ->records(fn (): array => collect($reports->lowStock(10))->keyBy(fn (array $row): string => "{$row['variant_id']}-{$row['location_id']}")->all())
            ->columns([
                TextColumn::make('product')
                    ->description(fn (array $record): ?string => $record['variant'])
                    ->wrap(),
                TextColumn::make('location')->visible(fn (): bool => app(CurrentShop::class)->getOrFail()->hasModule('multi_location')),
                TextColumn::make('sku')->label('SKU')->visibleFrom('md'),
                TextColumn::make('on_hand')
                    ->label('On hand')
                    ->formatStateUsing($quantity)
                    ->color(fn ($state): ?string => (float) $state < 0 ? 'danger' : null)
                    ->weight(fn ($state): ?string => (float) $state < 0 ? 'bold' : null)
                    ->alignEnd(),
                TextColumn::make('reorder_level')->label('Reorder at')->formatStateUsing($quantity)->alignEnd(),
            ])
            ->recordUrl(fn (array $record): string => StockLevelResource::getUrl('index', ['search' => $record['sku']]))
            ->headerActions([
                Action::make('allLowStock')
                    ->label('Open stock page')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(StockLevelResource::getUrl('index', ['filters' => ['low_stock' => ['isActive' => true]]])),
            ])
            ->paginated(false)
            ->emptyStateHeading('Nothing is running low');
    }
}
