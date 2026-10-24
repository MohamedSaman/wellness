<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Items created while receiving goods by someone who can't manage products
 * (ReceivingItemService): received, but not for sale until an admin reviews
 * them and sets their prices. Shown to product managers while any wait.
 */
class ItemsToReview extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('products.manage') && Product::query()->where('needs_review', true)->exists();
    }

    protected function getColumns(): int
    {
        return 1;
    }

    protected function getStats(): array
    {
        $count = Product::query()->where('needs_review', true)->count();

        return [
            Stat::make('Items to review', number_format($count))
                ->description('Created while receiving goods; not for sale until reviewed and priced.')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color('warning')
                ->url(ProductResource::getUrl('index', ['filters' => ['needs_review' => ['isActive' => true]]])),
        ];
    }
}
