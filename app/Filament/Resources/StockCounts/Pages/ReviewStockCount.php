<?php

namespace App\Filament\Resources\StockCounts\Pages;

use App\Exceptions\PosException;
use App\Filament\Resources\StockCounts\StockCountResource;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Filament\Support\ShopMoney;
use App\Models\ProductVariant;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\User;
use App\Services\StockCountService;
use App\Support\Quantity;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Review a count before posting (stock.adjust): each counted line with what
 * the system held when it was counted (the snapshot), the count, what the
 * system holds now, the variance (counted - snapshot) and its value at the
 * location's average cost, and the totals. Posting writes those variances
 * in one transaction.
 */
class ReviewStockCount extends Page implements HasTable
{
    use InteractsWithRecord, InteractsWithTable;

    protected static string $resource = StockCountResource::class;

    /** @var array{lines: list<array{line: StockCountLine|null, variant: ProductVariant, batch: string|null, snapshot: float|null, counted: float|null, current: float, variance: float|null, value: int|null}>, counted: int, uncounted: int, gain: int, loss: int, net: int}|null */
    private ?array $review = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(self::user()?->can('stock.adjust') && self::user()->can('view', $this->record), 403);
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public function stockCount(): StockCount
    {
        /** @var StockCount $record */
        $record = $this->getRecord();

        return $record;
    }

    /** @return array{lines: list<array{line: StockCountLine|null, variant: ProductVariant, batch: string|null, snapshot: float|null, counted: float|null, current: float, variance: float|null, value: int|null}>, counted: int, uncounted: int, gain: int, loss: int, net: int} */
    private function review(): array
    {
        return $this->review ??= app(StockCountService::class)->review($this->stockCount());
    }

    public function getTitle(): string|Htmlable
    {
        return 'Review '.$this->stockCount()->count_number;
    }

    public function getSubheading(): ?string
    {
        $review = $this->review();
        $canSeeCost = self::user()?->can('cost.view') ?? false;

        return sprintf(
            '%d counted, %d not counted (left as they are).%s Each variance is the count less what the system held for the line when it was counted; what sold or moved since is not a variance.',
            $review['counted'],
            $review['uncounted'],
            $canSeeCost ? sprintf(' Found %s, missing %s, net %s at cost.', ShopMoney::format($review['gain']), ShopMoney::format(-$review['loss']), ShopMoney::format($review['net'])) : '',
        );
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $quantity = fn ($state): string => $state === null ? '-' : Quantity::format((float) $state);

        return $table
            ->records(fn (): array => collect($this->review()['lines'])
                ->filter(fn (array $row): bool => $row['variance'] !== null)
                ->map(fn (array $row): array => [
                    'item' => StockTransferResource::itemLabel($row['variant']),
                    'unit' => $row['variant']->product->unit,
                    'batch' => $row['batch'],
                    'snapshot' => $row['snapshot'],
                    'counted' => $row['counted'],
                    'current' => $row['current'],
                    'variance' => $row['variance'],
                    'value' => $row['value'],
                ])
                ->values()
                ->all())
            ->columns([
                TextColumn::make('item')->label('Item')->wrap(),
                TextColumn::make('batch')->label('Batch')->placeholder('-'),
                TextColumn::make('snapshot')
                    ->label('System when counted')
                    ->tooltip('What the system held for the line when it was counted: the variance is measured from it')
                    ->formatStateUsing($quantity)
                    ->alignEnd(),
                TextColumn::make('counted')->label('Counted')->formatStateUsing($quantity)->alignEnd(),
                TextColumn::make('current')
                    ->label('System now')
                    ->tooltip('Sales, receipts and transfers since the line was counted are not variances')
                    ->formatStateUsing($quantity)
                    ->alignEnd(),
                TextColumn::make('variance')
                    ->label('Variance')
                    ->formatStateUsing(fn ($state, array $record): string => ((float) $state > 0 ? '+' : '').Quantity::format((float) $state).' '.$record['unit'])
                    ->color(fn ($state): ?string => (float) $state == 0.0 ? null : ((float) $state < 0 ? 'danger' : 'success'))
                    ->alignEnd(),
                TextColumn::make('value')
                    ->label('Value at cost')
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : ShopMoney::format($state))
                    ->alignEnd()
                    ->visible(fn (): bool => self::user()?->can('cost.view') ?? false),
            ])
            ->paginated(false)
            ->emptyStateHeading('Nothing counted yet');
    }

    protected function getHeaderActions(): array
    {
        $count = $this->stockCount();

        return [
            Action::make('back')
                ->label('Back to counting')
                ->color('gray')
                ->url(StockCountResource::getUrl('count', ['record' => $count])),
            Action::make('post')
                ->label('Post count')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->visible(fn (): bool => $count->isInProgress())
                ->requiresConfirmation()
                ->modalHeading('Post '.$count->count_number)
                ->modalDescription('Every counted line\'s variance (counted less what the system held when it was counted) becomes a count correction, in one go. Lines not counted are left as they are. This can\'t be undone, only counted again.')
                ->modalSubmitActionLabel('Post')
                ->action(function (Action $action) use ($count): void {
                    try {
                        app(StockCountService::class)->post($count, self::user() ?? abort(403));
                    } catch (PosException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();
                        $action->halt();
                    }

                    Notification::make()->title("{$count->count_number} posted")->success()->send();
                    $action->redirect(StockCountResource::getUrl('count', ['record' => $count]));
                }),
        ];
    }
}
