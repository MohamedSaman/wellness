<?php

namespace App\Filament\Resources\SupplierPayables\Widgets;

use App\Filament\Resources\SupplierPayables\Pages\ListSupplierPayables;
use App\Filament\Support\ShopMoney;
use App\Models\SupplierPayable;
use App\Services\SupplierPayablesService;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What the payables table shows, by how late it is: not yet due, 1–30,
 * 31–60, 61–90 and over 90 days past due. Follows the table's filters
 * (a supplier, a status).
 */
class PayablesAging extends StatsOverviewWidget
{
    use InteractsWithPageTable;

    protected function getTablePage(): string
    {
        return ListSupplierPayables::class;
    }

    protected function getColumns(): int|array
    {
        return ['default' => 2, 'md' => 3, 'xl' => 6];
    }

    protected function getStats(): array
    {
        $today = app(SupplierPayablesService::class)->today();
        $buckets = array_fill_keys(array_keys(SupplierPayablesService::AGING_BUCKETS), 0);

        $payables = $this->getPageTableQuery()
            ->setEagerLoads([])
            ->reorder()
            ->get(['id', 'due_on', 'amount', 'allocated', 'status']);

        foreach ($payables as $payable) {
            if ($payable instanceof SupplierPayable && $payable->status->isOpen()) {
                $buckets[SupplierPayablesService::bucketOf($payable->daysOverdue($today))] += $payable->outstanding();
            }
        }

        $stats = [];

        foreach (SupplierPayablesService::AGING_BUCKETS as $key => $label) {
            $stats[] = Stat::make($label, ShopMoney::format($buckets[$key]))
                ->color($key !== 'current' && $buckets[$key] > 0 ? 'danger' : null);
        }

        $stats[] = Stat::make('Total outstanding', ShopMoney::format(array_sum($buckets)));

        return $stats;
    }
}
