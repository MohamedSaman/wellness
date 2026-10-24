<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SupplierPayables\SupplierPayableResource;
use App\Filament\Support\PayablesForm;
use App\Filament\Support\ShopMoney;
use App\Services\SupplierPayablesService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What the shop owes its suppliers that is overdue, and what falls due in
 * the next seven days (today included). Purchasing on, for those who read
 * payables (payables.view or purchasing.manage). Each opens the Payables
 * screen filtered to it.
 */
class SupplierPayablesDue extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return PayablesForm::canSee();
    }

    protected function getColumns(): int|array
    {
        return ['default' => 1, 'sm' => 2];
    }

    protected function getStats(): array
    {
        $due = app(SupplierPayablesService::class)->dueSoon();
        $count = fn (int $n): string => $n.' '.str('invoice')->plural($n);

        return [
            Stat::make('Overdue to suppliers', ShopMoney::format($due['overdue']['amount']))
                ->description($due['overdue']['count'] > 0 ? $count($due['overdue']['count']).' past the due date' : 'Nothing overdue')
                ->descriptionIcon($due['overdue']['count'] > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->color($due['overdue']['count'] > 0 ? 'danger' : 'success')
                ->url(SupplierPayableResource::getUrl('index', ['filters' => ['overdue' => ['isActive' => true]]])),
            Stat::make('Due this week', ShopMoney::format($due['due_this_week']['amount']))
                ->description($count($due['due_this_week']['count']).' due by '.$due['week_ends']->format('D d/m'))
                ->descriptionIcon(Heroicon::OutlinedCalendarDays)
                ->color($due['due_this_week']['count'] > 0 ? 'warning' : null)
                ->url(SupplierPayableResource::getUrl('index', ['filters' => ['due_this_week' => ['isActive' => true]]])),
        ];
    }
}
