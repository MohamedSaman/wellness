<?php

namespace App\Filament\Resources\StockCounts;

use App\Enums\StockCountStatus;
use App\Filament\Resources\PermissionResource;
use App\Filament\Resources\StockCounts\Pages\CountStockCount;
use App\Filament\Resources\StockCounts\Pages\CreateStockCount;
use App\Filament\Resources\StockCounts\Pages\ListStockCounts;
use App\Filament\Resources\StockCounts\Pages\ReviewStockCount;
use App\Filament\Support\ShopMoney;
use App\Models\Category;
use App\Models\Location;
use App\Models\StockCount;
use App\Support\CurrentShop;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Stock counts (docs/PHARMACY.md §9, branch 10): start a count for a
 * location (all items, a category or a shelf), print the sheet (blind or
 * not), enter what was counted per item or batch in any unit, review the
 * variances with their value, and post them in one go (stock.adjust).
 * Counts never block sales; variances are taken at posting time
 * (StockCountService).
 */
class StockCountResource extends PermissionResource
{
    protected static ?string $model = StockCount::class;

    protected static ?string $slug = 'stock-counts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Stock counts';

    protected static ?string $modelLabel = 'stock count';

    protected static ?int $navigationSort = 7;

    public static function getNavigationBadge(): ?string
    {
        $open = static::canAccess() ? StockCount::query()->where('status', StockCountStatus::InProgress)->count() : 0;

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Counts in progress';
    }

    public static function multiLocation(): bool
    {
        return app(CurrentShop::class)->getOrFail()->hasModule('multi_location');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    Select::make('location_id')
                        ->label('Location')
                        ->options(fn (): array => Location::options())
                        ->default(fn (): ?int => static::user()?->workLocation(app(CurrentShop::class)->getOrFail())->getKey())
                        ->required()
                        ->native(false)
                        ->visible(fn (): bool => static::multiLocation()),
                    Toggle::make('blind')
                        ->label('Blind count')
                        ->helperText('The sheet and the counting screen leave out what the system holds, so the count is not led by it. The owner sees the differences on review.'),
                    Select::make('category_id')
                        ->label('Only this category')
                        ->options(fn (): array => Category::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->placeholder('All items'),
                    TextInput::make('bin')
                        ->label('Only shelves / bins starting with')
                        ->maxLength(50)
                        ->placeholder('e.g. A or A3-'),
                    Textarea::make('note')->rows(2)->maxLength(500)->columnSpanFull()->placeholder('Month-end count, counted by ...'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['location', 'starter'])
                ->withCount(['lines', 'lines as counted_count' => fn (Builder $lines) => $lines->whereNotNull('counted_quantity')]))
            ->columns([
                TextColumn::make('count_number')->label('Count')->weight('bold')->searchable(),
                TextColumn::make('started_at')->label('Started')->dateTime()->sortable(),
                TextColumn::make('location.name')->label('Location')->visible(fn (): bool => static::multiLocation()),
                TextColumn::make('coverage')->label('Covers')->state(fn (StockCount $record): string => $record->coverage()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (StockCountStatus $state): string => $state->label())
                    ->color(fn (StockCountStatus $state): string => $state->color()),
                TextColumn::make('counted_count')
                    ->label('Counted')
                    ->formatStateUsing(fn (int $state, StockCount $record): string => "{$state} of {$record->lines_count}"),
                IconColumn::make('blind')->boolean()->toggleable(),
                TextColumn::make('variance_value')
                    ->label('Variance at cost')
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : ShopMoney::format($state))
                    ->placeholder('-')
                    ->color(fn (?int $state): ?string => $state === null || $state === 0 ? null : ($state < 0 ? 'danger' : 'success'))
                    ->visible(fn (): bool => static::user()?->can('cost.view') ?? false),
                TextColumn::make('starter.name')->label('By')->placeholder('-')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(StockCountStatus::options()),
                SelectFilter::make('location_id')->label('Location')->options(fn (): array => Location::options())->visible(fn (): bool => static::multiLocation()),
            ])
            ->defaultSort('started_at', 'desc')
            ->recordUrl(fn (StockCount $record): string => static::getUrl('count', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockCounts::route('/'),
            'create' => CreateStockCount::route('/create'),
            'count' => CountStockCount::route('/{record}'),
            'review' => ReviewStockCount::route('/{record}/review'),
        ];
    }
}
