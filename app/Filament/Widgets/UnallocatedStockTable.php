<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\StockBatches\StockBatchResource;
use App\Models\StockBatch;
use App\Models\User;
use App\Services\BatchService;
use App\Support\CurrentShop;
use App\Support\Quantity;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The unallocated stock alert (docs/PHARMACY.md §6.4, decided): items sold
 * beyond their recorded batches (offline, or with negative stock allowed),
 * per location, until a delivery or the Settle action brings them back to
 * zero. Shown only while there is something to settle.
 */
class UnallocatedStockTable extends TableWidget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();
        $shop = app(CurrentShop::class)->get();

        return $user instanceof User
            && $user->can('stock.view')
            && $shop !== null
            && $shop->hasModule('pharmacy')
            && StockBatch::query()->where('is_unallocated', true)->where('quantity', '!=', 0)->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Unallocated stock')
            ->description('Sold beyond the recorded batches. The next delivery of the item settles it; or settle it by hand from the batch the units came from.')
            ->records(fn (): array => app(BatchService::class)->unallocatedStock()
                ->keyBy(fn (StockBatch $batch): int => (int) $batch->getKey())
                ->map(fn (StockBatch $batch): array => [
                    'id' => $batch->getKey(),
                    'product' => $batch->variant->product->name,
                    'sku' => $batch->variant->sku,
                    'location' => $batch->location->name,
                    'quantity' => Quantity::format((float) $batch->quantity).' '.$batch->variant->product->unit,
                    'negative' => (float) $batch->quantity < 0,
                ])
                ->all())
            ->columns([
                TextColumn::make('product')->description(fn (array $record): string => $record['sku'])->wrap(),
                TextColumn::make('location')->visible(fn (): bool => app(CurrentShop::class)->getOrFail()->hasModule('multi_location')),
                TextColumn::make('quantity')
                    ->label('Unallocated')
                    ->color(fn (array $record): string => $record['negative'] ? 'danger' : 'warning')
                    ->weight('bold')
                    ->alignEnd(),
            ])
            ->recordUrl(fn (): string => StockBatchResource::getUrl('index', ['filters' => ['unallocated' => ['isActive' => true]]]))
            ->headerActions([
                Action::make('allUnallocated')
                    ->label('Open batches')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(StockBatchResource::getUrl('index', ['filters' => ['unallocated' => ['isActive' => true]]])),
            ])
            ->paginated(false);
    }
}
