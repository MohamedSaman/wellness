<?php

namespace App\Filament\Widgets;

use App\Filament\Support\ShopMoney;
use App\Filament\Widgets\Concerns\NeedsReportsPermission;
use App\Services\ReportService;
use App\Support\CurrentShop;
use App\Support\ReportPeriod;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The 10 best sellers by quantity this week (Monday to today, shop time),
 * net of returns; in a pharmacy the top 10 medicines this month
 * (docs/PHARMACY.md §9, branch 11).
 */
class BestSellersTable extends TableWidget
{
    use NeedsReportsPermission;

    protected static ?int $sort = 7;

    public function table(Table $table): Table
    {
        $shop = app(CurrentShop::class)->getOrFail();
        $pharmacy = $shop->hasModule('pharmacy');

        return $table
            ->heading($pharmacy ? 'Top 10 medicines this month' : 'Best sellers this week')
            ->records(fn (): array => collect(app(ReportService::class)->bestSellers($pharmacy ? ReportPeriod::thisMonth($shop) : ReportPeriod::thisWeek($shop), 10))
                ->keyBy('sku')
                ->all())
            ->columns([
                TextColumn::make('product')
                    ->description(fn (array $record): ?string => $record['variant'])
                    ->wrap(),
                TextColumn::make('sku')->label('SKU')->visibleFrom('md'),
                TextColumn::make('quantity')
                    ->label('Sold')
                    ->formatStateUsing(fn ($state): string => rtrim(rtrim(number_format((float) $state, 3), '0'), '.'))
                    ->alignEnd(),
                TextColumn::make('net')
                    ->label('Net sales')
                    ->formatStateUsing(fn ($state): ?string => ShopMoney::format((int) $state))
                    ->alignEnd(),
            ])
            ->paginated(false)
            ->emptyStateHeading($pharmacy ? 'No sales yet this month' : 'No sales yet this week');
    }
}
